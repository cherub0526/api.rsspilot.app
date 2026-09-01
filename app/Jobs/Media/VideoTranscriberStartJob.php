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
     * 遠端滿載時的重試間隔。比認證那條短很多——等的是別人的轉錄跑完，
     * 通常幾分鐘內就會空出名額。
     */
    protected const int BUSY_RETRY_DELAY_SECONDS = 60;

    /**
     * 塞車重試的總時限。刻意壓在 uniqueFor（3600）之內：超過的話唯一鎖會先
     * 過期，同一筆 media 就可能被重複派工。
     */
    protected const int RETRY_UNTIL_SECONDS = 3000;

    /**
     * Must cover every release() this job can make, otherwise the worker fails
     * the job on the second attempt instead of letting it retry.
     *
     * 注意：一旦定義了 retryUntil()，worker 就完全不看這個值
     * （vendor/hypervel/queue/src/Worker.php:563 的 `! $retryUntil &&`）。
     * 保留它是為了留下意圖，實際的次數上限由 releaseForAuthRetry() 自己的
     * attempts() 檢查把關。
     */
    public int $tries = self::MAX_ATTEMPTS;

    public int $uniqueFor = 3600;

    /**
     * 用時間上限取代次數上限。
     *
     * 塞車時要退回重試幾次是無法預先知道的——取決於前面那些轉錄多久跑完。
     * 用次數當預算會讓「排隊太久」被誤判成「這筆轉錄失敗」，而其實什麼都
     * 沒壞。時間上限才貼近「等到有名額為止」的語意。
     */
    public function retryUntil(): int
    {
        return time() + self::RETRY_UNTIL_SECONDS;
    }

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
        // 主動閘門：名額滿了就別浪費 getUrlInfo 與 startTranscription 兩趟
        // 呼叫。下面仍然保留對 busy code 的處理，因為這個計數是本地推估的，
        // 會跟遠端漂移（media 被刪、fetch 沒跑成功都會讓計數卡住）。
        if ($this->transcribingCount() >= $this->maxConcurrent()) {
            $this->release(self::BUSY_RETRY_DELAY_SECONDS);
            return;
        }

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

        $code = $startTranscription['code'] ?? null;

        // 滿載是暫時的，不是這筆的問題。標記失敗會讓 media 再也不會自己
        // 回來，只因為當下剛好排在第六個。
        if ($this->isBusyCode($code)) {
            $this->release(self::BUSY_RETRY_DELAY_SECONDS);
            return;
        }

        if ($code !== 100000) {
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

    /**
     * 本地推估的在途轉錄數。遠端的上限算的是「處理中的任務」，而那正好對應
     * 到停在 transcribing 的 media——它們要等 VideoTranscriberFetchJob 取回
     * 結果才會離開這個狀態。
     */
    private function transcribingCount(): int
    {
        return Media::query()
            ->where('status', Media::STATUS_TRANSCRIBING)
            ->count();
    }

    private function maxConcurrent(): int
    {
        return (int) config('services.videotranscriber.max_concurrent', 5);
    }

    private function isBusyCode(mixed $code): bool
    {
        if (!is_numeric($code)) {
            return false;
        }

        $busyCodes = array_map(
            'intval',
            (array) config('services.videotranscriber.busy_codes', [])
        );

        return in_array((int) $code, $busyCodes, true);
    }

    private function markTranscribeFailed(): void
    {
        $this->media->fill(['status' => Media::STATUS_TRANSCRIBE_FAILED])->save();
    }
}
