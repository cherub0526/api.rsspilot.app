<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Exception;
use Throwable;
use App\Models\Media;
use App\Models\Caption;
use Hypervel\Queue\Queueable;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Http;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use App\Exceptions\VideoTranscriberAuthException;
use App\Services\VideoTranscriber\VideoTranscriberClient;

class VideoTranscriberFetchJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    protected const MAX_ATTEMPTS = 60;

    protected const RETRY_DELAY_SECONDS = 60;

    /**
     * Longer than RETRY_DELAY_SECONDS: waiting on the transcription is normal
     * and quick to recheck, whereas an unusable account needs someone to fix
     * the credentials first.
     */
    protected const AUTH_RETRY_DELAY_SECONDS = 300;

    protected const STATUS_READY = 'ready';

    protected const STATUS_FAILED = 'failed';

    /**
     * Subtitle versions from best to worst. `ai_enhanced` is the only one
     * with punctuation and corrected wording; `optimized` merely re-splits
     * `original`'s text into finer segments.
     */
    protected const VERSION_PRIORITY = ['ai_enhanced', 'optimized', 'original'];

    /**
     * Record-level statuses that mean the service is done with this audio.
     * While it is still working the response carries no `versions` key at
     * all, so the versions alone cannot tell waiting from failure.
     */
    protected const RECORD_SETTLED_STATUSES = ['success', 'failed'];

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
        } catch (VideoTranscriberAuthException) {
            $this->releaseForAuthRetry();
            return;
        } catch (Exception $e) {
            $this->saveTranscription(['error' => $e->getMessage()]);
            $this->markTranscribeFailed();
            return;
        }

        if (($transcription['code'] ?? null) !== 100000) {
            $this->saveTranscription($transcription);
            $this->markTranscribeFailed();
            return;
        }

        $data = $transcription['data'] ?? [];
        $version = $this->selectVersion($data['versions'] ?? []);

        if ($version === null) {
            if ($this->hasSettled($data) || $this->attempts() >= self::MAX_ATTEMPTS) {
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
                'locale'   => $this->detectLocale($data['versions']['original']['subtitle_url'] ?? null),
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
     * Persist why getTranscription did not yield a usable result — either the
     * rejected response itself, or an `error` key standing in for it when the
     * call failed before any response body existed. Without it a
     * `transcribe_failed` media carries no trace of why it stopped here.
     *
     * Only failures are stored, and deliberately so: a successful response
     * embeds every subtitle of the recording and routinely outgrows the
     * MEDIUMTEXT column, whereas a failure is a few hundred bytes. Nothing
     * downstream reads the column back, so skipping the successful payload
     * costs only the audit trail. Restore the unconditional write once the
     * column has been widened to LONGTEXT.
     *
     * The auth path writes nothing either — it releases for a retry, so it has
     * no outcome yet and would only overwrite what the next attempt stores.
     */
    private function saveTranscription(array $transcription): void
    {
        VideoTranscription::updateOrCreate(
            ['media_id' => $this->media->id],
            ['transcription' => $transcription]
        );
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
     * True once the record and every version it carries are ready or failed,
     * meaning no amount of waiting will produce a better result. A record
     * that is still processing has no `versions` key yet, so it must be
     * checked first — otherwise the missing versions read as failures.
     *
     * @param array<string, mixed> $data
     */
    private function hasSettled(array $data): bool
    {
        if (!in_array($data['status'] ?? null, self::RECORD_SETTLED_STATUSES, true)) {
            return false;
        }

        foreach (self::VERSION_PRIORITY as $name) {
            $status = $data['versions'][$name]['status'] ?? self::STATUS_FAILED;

            if ($status !== self::STATUS_READY && $status !== self::STATUS_FAILED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the caption locale from the language videotranscriber.ai
     * detected. The API response itself always leaves `asr_lang_code`
     * empty — the language only appears inside the file the `original`
     * version's `subtitle_url` points at, so this costs one extra request.
     * Anything unreadable falls back to English.
     */
    private function detectLocale(?string $subtitleUrl): string
    {
        if (!$subtitleUrl) {
            return Caption::LOCAL_EN;
        }

        try {
            $langCode = (string) (Http::get($subtitleUrl)->json()['detected_language'] ?? '');
        } catch (Throwable) {
            return Caption::LOCAL_EN;
        }

        return $langCode === '' ? Caption::LOCAL_EN : $this->mapLocale($langCode);
    }

    /**
     * Map an ISO 639-1 code onto the locales captions are stored under,
     * matching what the YouTube caption jobs already do.
     */
    private function mapLocale(string $langCode): string
    {
        return match (true) {
            str_starts_with($langCode, 'zh') => Caption::LOCAL_ZH_TW,
            str_starts_with($langCode, 'en') => Caption::LOCAL_EN,
            default                          => $langCode,
        };
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
