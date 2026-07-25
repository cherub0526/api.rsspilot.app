<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Exception;
use App\Models\Media;
use App\Models\Caption;
use Hypervel\Queue\Queueable;
use App\Models\VideoTranscription;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use App\Services\VideoTranscriber\VideoTranscriberClient;

class VideoTranscriberFetchJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    protected const MAX_ATTEMPTS = 60;

    protected const RETRY_DELAY_SECONDS = 60;

    public int $uniqueFor = 3600;

    protected Media $media;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media)
    {
        $this->media = $media;

        $this->queue = 'videotranscriber.fetch';
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
        $startTranscription = $this->media->videoTranscription?->start_transcription ?? [];
        $audioId = $startTranscription['data']['audio_id'] ?? null;

        if (!$audioId) {
            $this->markTranscribeFailed();
            return;
        }

        try {
            $transcription = $client->getTranscription($audioId);
        } catch (Exception) {
            $this->markTranscribeFailed();
            return;
        }

        if (($transcription['code'] ?? null) !== 100000) {
            $this->markTranscribeFailed();
            return;
        }

        VideoTranscription::updateOrCreate(
            ['media_id' => $this->media->id],
            ['transcription' => $transcription]
        );

        $original = $transcription['data']['versions']['original'] ?? [];

        if (($original['status'] ?? null) !== 'ready') {
            if ($this->attempts() >= self::MAX_ATTEMPTS) {
                $this->markTranscribeFailed();
                return;
            }

            $this->release(self::RETRY_DELAY_SECONDS);
            return;
        }

        [$text, $segments] = $this->buildCaptionContent($original['subtitles'] ?? []);

        Caption::updateOrCreate(
            [
                'media_id' => $this->media->id,
                'locale'   => Caption::LOCAL_EN,
            ],
            [
                'primary'       => true,
                'text'          => $text,
                'segments'      => $segments,
                'word_segments' => [],
            ]
        );

        $this->media->fill(['status' => Media::STATUS_TRANSCRIBED])->save();
    }

    /**
     * Build the flat caption text and start/end-in-seconds segments from
     * videotranscriber.ai's `versions.original.subtitles` payload.
     *
     * @param array<int, array<string, mixed>> $subtitles
     * @return array{string, array<int, array<string, mixed>>}
     */
    private function buildCaptionContent(array $subtitles): array
    {
        $textParts = [];
        $segments = [];

        foreach ($subtitles as $subtitle) {
            $content = trim((string) ($subtitle['text'] ?? ''));

            if ($content === '') {
                continue;
            }

            $textParts[] = $content;
            $segments[] = [
                'start' => $this->timeToSeconds((string) ($subtitle['start'] ?? '00:00:00')),
                'end'   => $this->timeToSeconds((string) ($subtitle['end'] ?? '00:00:00')),
                'text'  => $content,
            ];
        }

        return [implode(' ', $textParts), $segments];
    }

    /**
     * Convert a "HH:MM:SS" timestamp into seconds.
     */
    private function timeToSeconds(string $time): float
    {
        $parts = array_map('intval', explode(':', $time));

        while (count($parts) < 3) {
            array_unshift($parts, 0);
        }

        [$hours, $minutes, $seconds] = $parts;

        return (float) ($hours * 3600 + $minutes * 60 + $seconds);
    }

    private function markTranscribeFailed(): void
    {
        $this->media->fill(['status' => Media::STATUS_TRANSCRIBE_FAILED])->save();
    }
}
