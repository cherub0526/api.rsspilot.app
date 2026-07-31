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

    protected const STATUS_READY = 'ready';

    protected const STATUS_FAILED = 'failed';

    /**
     * Subtitle versions from best to worst. `ai_enhanced` is the only one
     * with punctuation and corrected wording; `optimized` merely re-splits
     * `original`'s text into finer segments.
     */
    protected const VERSION_PRIORITY = ['ai_enhanced', 'optimized', 'original'];

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

        $versions = $transcription['data']['versions'] ?? [];
        $version = $this->selectVersion($versions);

        if ($version === null) {
            if ($this->hasSettled($versions) || $this->attempts() >= self::MAX_ATTEMPTS) {
                $this->markTranscribeFailed();
                return;
            }

            $this->release(self::RETRY_DELAY_SECONDS);
            return;
        }

        [$text, $segments] = $this->buildCaptionContent($version['subtitles']);

        if (!$segments) {
            $this->markTranscribeFailed();
            return;
        }

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
     * Pick the best usable version out of videotranscriber.ai's `versions`
     * payload. Returns null while a better version is still being generated,
     * so the job waits for `ai_enhanced` instead of settling for `original`.
     *
     * A version that is ready but carries no subtitles is skipped like a
     * failed one — the API reports `ready` even when it transcribed nothing.
     *
     * @param array<string, array<string, mixed>> $versions
     * @return null|array<string, mixed>
     */
    private function selectVersion(array $versions): ?array
    {
        foreach (self::VERSION_PRIORITY as $name) {
            $version = $versions[$name] ?? null;
            $status = $version['status'] ?? self::STATUS_FAILED;

            if ($status === self::STATUS_FAILED) {
                continue;
            }

            if ($status !== self::STATUS_READY) {
                return null;
            }

            if ($version['subtitles'] ?? []) {
                return $version;
            }
        }

        return null;
    }

    /**
     * True once every version is ready or failed, meaning no amount of
     * waiting will produce a better result.
     *
     * @param array<string, array<string, mixed>> $versions
     */
    private function hasSettled(array $versions): bool
    {
        foreach (self::VERSION_PRIORITY as $name) {
            $status = $versions[$name]['status'] ?? self::STATUS_FAILED;

            if ($status !== self::STATUS_READY && $status !== self::STATUS_FAILED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the flat caption text and start/end-in-seconds segments from a
     * version's `subtitles` payload.
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
                'start' => $this->timeToSeconds($subtitle['start'] ?? 0),
                'end'   => $this->timeToSeconds($subtitle['end'] ?? 0),
                'text'  => $content,
            ];
        }

        return [implode(' ', $textParts), $segments];
    }

    /**
     * Convert a timestamp into seconds. `original` uses "HH:MM:SS" strings
     * while `optimized` and `ai_enhanced` use fractional seconds.
     */
    private function timeToSeconds(mixed $time): float
    {
        if (is_numeric($time)) {
            return (float) $time;
        }

        $parts = array_map('floatval', explode(':', (string) $time));

        while (count($parts) < 3) {
            array_unshift($parts, 0.0);
        }

        [$hours, $minutes, $seconds] = $parts;

        return $hours * 3600 + $minutes * 60 + $seconds;
    }

    private function markTranscribeFailed(): void
    {
        $this->media->fill(['status' => Media::STATUS_TRANSCRIBE_FAILED])->save();
    }
}
