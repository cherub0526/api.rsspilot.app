<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Exception;
use App\Models\Media;
use Hypervel\Queue\Queueable;
use App\Models\VideoTranscription;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use App\Exceptions\VideoTranscriberAuthException;
use App\Services\VideoTranscriber\VideoTranscriberClient;

class VideoTranscriberStartJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * How long to wait before retrying once the shared videotranscriber.ai
     * account turns out to be unusable. Long enough that a broken password can
     * be fixed by hand before the attempts run out.
     */
    protected const int AUTH_RETRY_DELAY_SECONDS = 300;

    protected const int MAX_ATTEMPTS = 12;

    /**
     * Must cover every release() this job can make, otherwise the worker fails
     * the job on the second attempt instead of letting it retry.
     */
    public int $tries = self::MAX_ATTEMPTS;

    public int $uniqueFor = 3600;

    protected Media $media;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media)
    {
        $this->media = $media;

        $this->queue = 'videotranscriber.start';
    }

    /**
     * The unique lock key, scoped per media so a media row can never have
     * two of this job in the queue (or executing) at the same time.
     */
    public function uniqueId(): string
    {
        return $this->media->id;
    }

    /**
     * Execute the job.
     */
    public function handle(VideoTranscriberClient $client): void
    {
        $videoId = $this->media->video_detail['yt:videoId'];
        $url = sprintf('https://www.youtube.com/watch?v=%s', $videoId);

        try {
            $urlInfo = $client->getUrlInfo($url);
        } catch (VideoTranscriberAuthException) {
            $this->releaseForAuthRetry();
            return;
        } catch (Exception $e) {
            $this->saveTranscription(['url_info' => ['error' => $e->getMessage()]]);
            $this->markTranscribeFailed();
            return;
        }

        $this->saveTranscription(['url_info' => $urlInfo]);

        if (($urlInfo['code'] ?? null) !== 100000) {
            $this->markTranscribeFailed();
            return;
        }

        $data = $urlInfo['data'] ?? [];

        $this->syncDuration($data);

        try {
            $startTranscription = $client->startTranscription([
                'path'       => $url,
                'type'       => $data['type'] ?? 3,
                'audio_time' => $data['audio_time'] ?? 0,
                'file_name'  => $data['title'] ?? '',
            ]);
        } catch (VideoTranscriberAuthException) {
            $this->releaseForAuthRetry();
            return;
        } catch (Exception $e) {
            $this->saveTranscription(['start_transcription' => ['error' => $e->getMessage()]]);
            $this->markTranscribeFailed();
            return;
        }

        $this->saveTranscription(['start_transcription' => $startTranscription]);

        if (($startTranscription['code'] ?? null) !== 100000) {
            $this->markTranscribeFailed();
            return;
        }

        $this->media->fill(['status' => Media::STATUS_TRANSCRIBING])->save();
    }

    /**
     * Persist an endpoint's outcome, successful or not.
     *
     * Both columns are written before their `code` is inspected: a rejection is
     * the only explanation for why a media stops where it does, and an `error`
     * key stands in when the call failed before any response body existed.
     * Without it a failure is indistinguishable from a media never started.
     *
     * The auth path deliberately writes nothing — it releases for a retry, so
     * it has no outcome yet and would only overwrite what the next attempt
     * stores.
     */
    private function saveTranscription(array $attributes): void
    {
        VideoTranscription::updateOrCreate(
            ['media_id' => $this->media->id],
            $attributes
        );
    }

    /**
     * Persist the video length returned by url-info onto the media row.
     */
    private function syncDuration(array $data): void
    {
        $duration = (int) ($data['youtube_video_data']['videoInfo']['duration'] ?? 0);

        if ($duration <= 0) {
            $duration = (int) ($data['audio_time'] ?? 0);
        }

        if ($duration <= 0 || $this->media->duration === $duration) {
            return;
        }

        $this->media->fill(['duration' => $duration])->save();
    }

    /**
     * Back off after the token could not be refreshed. The media keeps its
     * current status so it resumes by itself once the account works again,
     * instead of a credential problem burning every queued media.
     */
    private function releaseForAuthRetry(): void
    {
        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            $this->markTranscribeFailed();
            return;
        }

        $this->release(self::AUTH_RETRY_DELAY_SECONDS);
    }

    private function markTranscribeFailed(): void
    {
        $this->media->fill(['status' => Media::STATUS_TRANSCRIBE_FAILED])->save();
    }
}
