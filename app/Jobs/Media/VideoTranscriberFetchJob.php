<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Exception;
use Throwable;
use App\Models\Media;
use App\Models\Caption;
use App\Utils\Const\ISO6391;
use App\Utils\ChineseVariant;
use Hypervel\Queue\Queueable;
use App\Services\YoutubeService;
use Hypervel\Support\Facades\Log;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
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
     * The one language whose locale needs more than a language code, and the
     * only one the subtitle-text arbitration understands.
     */
    protected const CHINESE = 'zh';

    /**
     * Where the raw getTranscription() response is archived, since the
     * `video_transcriptions.transcription` column cannot hold a successful
     * payload.
     */
    protected const TRANSCRIPTION_DISK = 's3';

    protected const TRANSCRIPTION_PATH = 'videotranscriber.ai/%s/transcribe.json';

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
    public function handle(VideoTranscriberClient $client, YoutubeService $youtube): void
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

        $this->archiveTranscription($transcription);

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

        $locale = $this->resolveLocale($youtube, $data, $text);

        Caption::updateOrCreate(
            [
                'media_id' => $this->media->id,
                'locale'   => $locale,
            ],
            [
                'primary'       => true,
                'text'          => $text,
                'segments'      => $segments,
                'word_segments' => [],
            ]
        );

        // media.language 存的是同一個結論。字幕語系要靠 captions 那筆才問得到，
        // 而「這支影片說什麼語言」是 media 自己的屬性，值得留在它身上。
        $this->media->fill([
            'status'   => Media::STATUS_TRANSCRIBED,
            'language' => $locale,
        ])->save();

        // Handed to its own job rather than done here: the assets include a
        // multi-MB mp3, and this job's timeout does not fail the job, it kills
        // the whole worker (docs/lore/transcription/pitfalls.md).
        dispatch(new VideoTranscriberArchiveJob($this->media));
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
     * column has been widened to LONGTEXT. The full payload is archived on
     * S3 regardless — see archiveTranscription().
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
     * Archive the raw response on S3, at a key fixed per media so each poll
     * overwrites the previous one and the object always holds the latest
     * thing the service said — including the in-progress responses, which are
     * small, and the rejected ones.
     *
     * This is the only complete copy of a successful payload: the DB column
     * cannot hold one (see saveTranscription()). Storage failures are logged
     * and swallowed — the archive is an audit trail, and losing it must not
     * fail a media whose captions are otherwise fine.
     *
     * @param array<string, mixed> $transcription
     */
    private function archiveTranscription(array $transcription): void
    {
        $path = sprintf(self::TRANSCRIPTION_PATH, $this->media->id);

        try {
            Storage::disk(self::TRANSCRIPTION_DISK)->put(
                $path,
                (string) json_encode($transcription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable $e) {
            Log::error('Failed to archive the videotranscriber.ai response.', [
                'media_id' => $this->media->id,
                'path'     => $path,
                'message'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Take the highest-priority version the service reports as ready.
     *
     * Only `status` is consulted: a ready version is accepted even when its
     * `subtitles` are empty, and a version still being generated is not waited
     * for — whatever is ready first wins. Both are deliberate, and both hand
     * the outcome to `buildCaptionContent()`, which fails the media when the
     * chosen version yields no usable segments.
     *
     * @param array<string, array<string, mixed>> $versions
     * @return null|array<string, mixed>
     */
    private function selectVersion(array $versions): ?array
    {
        foreach (self::VERSION_PRIORITY as $name) {
            $version = $versions[$name] ?? null;
            $status = $version['status'] ?? self::STATUS_FAILED;

            if ($status === self::STATUS_READY) {
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
     * Decide what language the audio is actually in.
     *
     * Nothing in the response body answers this. `extra_data.asr_lang_code`
     * comes back empty every time, and the neighbouring `client_lang_code` is
     * the interface language of whoever submitted the job — it reads `en` for
     * a Mandarin video, so reaching for it is worse than having nothing.
     *
     * Two real sources, in this order of authority:
     *
     * 1. YouTube's `defaultAudioLanguage`, which is the only one that carries
     *    a region or script (`zh-TW`, `zh-Hant`). It is declared by the
     *    uploader, so it can be wrong or absent — 11 of 12 sampled channels
     *    had it.
     * 2. The language videotranscriber.ai detected from the audio, which is
     *    trustworthy but only ever two letters (`zh`), so it cannot tell
     *    Traditional from Simplified on its own.
     *
     * They settle different halves of the question, hence reconcile(): the
     * spoken language wins on *which* language, the declared code wins on
     * region and script.
     *
     * @param array<string, mixed> $data
     */
    private function resolveLocale(YoutubeService $youtube, array $data, string $text): string
    {
        $spoken = $this->detectSpokenLanguage($data['versions']['original']['subtitle_url'] ?? null);
        $declared = $this->declaredAudioLanguage($youtube);

        $locale = $this->reconcile($spoken, $declared);

        if ($locale === '') {
            return Caption::LOCAL_EN;
        }

        // Anything carrying a region is already an answer, and for every
        // language but Chinese a bare code is the whole answer too. Running
        // the character test on Japanese would be actively wrong — shinjitai
        // shares glyphs with Simplified (学, 会, 体, 点), so it would label a
        // Japanese video `zh-CN`.
        if ($locale !== self::CHINESE) {
            return $locale;
        }

        // Bare `zh`: neither source pinned the variant down, so the subtitles
        // themselves get the last word — and they are already in hand, so it
        // costs nothing. Undecidable text falls back to Traditional, because
        // `available_locales` has no plain `zh` for a user setting to match.
        return ChineseVariant::detect($text) ?? Caption::LOCAL_ZH_TW;
    }

    /**
     * Trust the audio for the language, the uploader for the region.
     *
     * When the two disagree on the language itself, the detection wins: it
     * listened to the recording, whereas a channel that sets one default for
     * every upload mislabels anything that does not match.
     */
    private function reconcile(string $spoken, string $declared): string
    {
        if ($declared === '') {
            return $spoken;
        }

        if ($spoken === '') {
            return $declared;
        }

        return ISO6391::language($declared) === ISO6391::language($spoken)
            ? $declared
            : $spoken;
    }

    /**
     * The language videotranscriber.ai detected, normalised, or '' when it
     * cannot be read. It only exists inside the file the `original` version's
     * `subtitle_url` points at, so this costs one extra request.
     */
    private function detectSpokenLanguage(?string $subtitleUrl): string
    {
        if (!$subtitleUrl) {
            return '';
        }

        try {
            $langCode = (string) (Http::get($subtitleUrl)->json()['detected_language'] ?? '');
        } catch (Throwable) {
            return '';
        }

        return $langCode === '' ? '' : ISO6391::normalize($langCode);
    }

    /**
     * The `defaultAudioLanguage` the uploader declared, normalised, or '' when
     * YouTube has nothing to say. Failures are swallowed: this is the richer
     * of the two sources, not a required one, and a quota error must not cost
     * a media its captions.
     */
    private function declaredAudioLanguage(YoutubeService $youtube): string
    {
        $videoId = $this->media->video_detail['yt:videoId'] ?? null;

        if (!$videoId) {
            return '';
        }

        try {
            $langCode = (string) ($youtube->getVideoDetails((string) $videoId)
                ?->getSnippet()
                ?->getDefaultAudioLanguage() ?? '');
        } catch (Throwable) {
            return '';
        }

        return $langCode === '' ? '' : ISO6391::normalize($langCode);
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
