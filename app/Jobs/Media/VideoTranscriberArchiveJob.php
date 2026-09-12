<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Throwable;
use App\Models\Media;
use Hypervel\Queue\Queueable;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Contracts\ShouldBeUnique;

/**
 * Pull every asset videotranscriber.ai produced for a media onto our own S3,
 * next to the response that lists them.
 *
 * Why this is worth a job of its own: the service drops a transcription record
 * after roughly a month — `getTranscription()` then answers `100027 not
 * found` — but the files on cdn.ng-resource.com outlive it. Once the record is
 * gone those URLs are the only way back to the subtitles, so the archive is
 * what makes an expired media recoverable at all.
 *
 * Why not inside VideoTranscriberFetchJob: that job already spends 3-4 external
 * calls per run and a timeout there does not kill the job, it SIGKILLs the
 * whole worker and leaves an orphaned unique lock behind
 * (docs/lore/transcription/pitfalls.md). Seven more downloads, one of them a
 * ~13MB mp3, would make that a routine event instead of a rare one.
 */
class VideoTranscriberArchiveJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    protected const int MAX_ATTEMPTS = 5;

    /**
     * Longer than the fetch job's: what fails here is a CDN read, and retrying
     * it in the next breath tends to fail the same way.
     */
    protected const int RETRY_DELAY_SECONDS = 120;

    /**
     * Generous because of the audio: a full episode's mp3 runs to tens of MB,
     * which is a different order of transfer from the JSON files.
     */
    protected const int DOWNLOAD_TIMEOUT_SECONDS = 120;

    protected const string DISK = 's3';

    protected const string BASE_PATH = 'videotranscriber.ai/%s';

    protected const string PAYLOAD_FILE = 'transcribe.json';

    protected const string AUDIO_FILE = 'audio.mp3';

    /**
     * Every version the service exposes, archived regardless of which one the
     * caption was built from — `ai_enhanced` is the readable one, `original`
     * is the only one carrying `detected_language`, and keeping all three
     * costs a few hundred KB.
     */
    protected const array VERSIONS = ['original', 'optimized', 'ai_enhanced'];

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

        $this->queue = 'videotranscriber.archive';
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
    public function handle(): void
    {
        $payload = $this->readPayload();

        if ($payload === null) {
            // Nothing to work from. Retrying cannot conjure the response, so
            // this ends here rather than burning the attempts.
            Log::warning('No archived videotranscriber.ai response to read assets from.', [
                'media_id' => $this->media->id,
                'path'     => $this->path(self::PAYLOAD_FILE),
            ]);
            return;
        }

        $assets = $this->collectAssets($payload['data'] ?? []);

        if (!$assets) {
            Log::warning('The archived videotranscriber.ai response lists no assets.', [
                'media_id' => $this->media->id,
            ]);
            return;
        }

        $failed = [];

        foreach ($assets as $file => $url) {
            if (!$this->store($url, $file)) {
                $failed[] = $file;
            }
        }

        if (!$failed) {
            return;
        }

        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            Log::error('Gave up archiving videotranscriber.ai assets.', [
                'media_id' => $this->media->id,
                'failed'   => $failed,
            ]);
            return;
        }

        // Whole-batch retry: every asset is overwritten in place, so
        // re-downloading the ones that already landed costs a transfer and
        // nothing else, and the job stays free of per-asset bookkeeping.
        $this->release(self::RETRY_DELAY_SECONDS);
    }

    /**
     * Read back the response VideoTranscriberFetchJob archived. It is the only
     * record of where the assets live — the URLs carry one-off UUIDs that
     * cannot be derived from the media.
     *
     * @return null|array<string, mixed>
     */
    private function readPayload(): ?array
    {
        try {
            $payload = json_decode(
                (string) Storage::disk(self::DISK)->get($this->path(self::PAYLOAD_FILE)),
                true
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * Map every asset URL in the response onto the name it is stored under.
     *
     * Named by meaning rather than by the CDN's filename: those carry a UUID
     * that changes on every re-transcription, whereas these keys stay
     * predictable, so a reader can address a file without parsing the payload
     * first. The original URLs remain in `transcribe.json` either way.
     *
     * @param array<string, mixed> $data
     * @return array<string, string> filename => url
     */
    private function collectAssets(array $data): array
    {
        $assets = [];

        foreach (self::VERSIONS as $version) {
            foreach (['transcript', 'subtitle'] as $kind) {
                $url = $data['versions'][$version][$kind . '_url'] ?? null;

                if (is_string($url) && $url !== '') {
                    // .json, not the CDN's .txt: the payload is JSON and the
                    // extension is what gives the object its content type.
                    $assets[sprintf('%s.%s.json', $version, $kind)] = $url;
                }
            }
        }

        $audioUrl = $data['extra_data']['video_audio_data']['audio_url'] ?? null;

        if (is_string($audioUrl) && $audioUrl !== '') {
            $assets[self::AUDIO_FILE] = $audioUrl;
        }

        return $assets;
    }

    /**
     * Download one asset and write it under the media's folder, overwriting
     * whatever was there. Overwriting is the point: a media holds exactly one
     * transcription, so a re-run is a refresh, never a second copy.
     */
    private function store(string $url, string $file): bool
    {
        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->get($url);

            if (!$response->successful()) {
                Log::warning('Failed to download a videotranscriber.ai asset.', [
                    'media_id' => $this->media->id,
                    'file'     => $file,
                    'status'   => $response->status(),
                ]);
                return false;
            }

            Storage::disk(self::DISK)->put($this->path($file), $response->body());

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to archive a videotranscriber.ai asset.', [
                'media_id' => $this->media->id,
                'file'     => $file,
                'message'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function path(string $file): string
    {
        return sprintf(self::BASE_PATH, $this->media->id) . '/' . $file;
    }
}
