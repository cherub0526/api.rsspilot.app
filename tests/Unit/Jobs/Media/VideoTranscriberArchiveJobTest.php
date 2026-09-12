<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use App\Jobs\Media\VideoTranscriberArchiveJob;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Jobs\Media\VideoTranscriberArchiveJob
 */
class VideoTranscriberArchiveJobTest extends TestCase
{
    use RefreshDatabase;

    private const CDN = 'https://cdn.ng-resource.com/product/resource/videotranscriber';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    /**
     * The shape VideoTranscriberFetchJob leaves on S3, trimmed to the keys
     * this job reads.
     */
    private function payload(): array
    {
        return [
            'code' => 100000,
            'data' => [
                'status'     => 'success',
                'extra_data' => [
                    'video_audio_data' => ['audio_url' => self::CDN . '/export/c8d2f580.mp3'],
                ],
                'versions' => [
                    'original' => [
                        'status'         => 'ready',
                        'transcript_url' => self::CDN . '/text/b7811036.txt',
                        'subtitle_url'   => self::CDN . '/text/bf1a413d-origin.txt',
                    ],
                    'optimized' => [
                        'status'         => 'ready',
                        'transcript_url' => self::CDN . '/text/31c0ebab-optimized.txt',
                        'subtitle_url'   => self::CDN . '/text/fded1083-optimized-subtitles.txt',
                    ],
                    'ai_enhanced' => [
                        'status'         => 'ready',
                        'transcript_url' => self::CDN . '/text/98a3580b-ai-enhanced.txt',
                        'subtitle_url'   => self::CDN . '/text/30b06762-ai-enhanced-subtitles.txt',
                    ],
                ],
            ],
        ];
    }

    private function createMediaWithPayload(?array $payload = null): Media
    {
        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        Storage::disk('s3')->put(
            sprintf('videotranscriber.ai/%s/transcribe.json', $media->id),
            (string) json_encode($payload ?? $this->payload())
        );

        return $media;
    }

    public function testStoresEveryVersionAndTheAudioUnderTheMediaFolder(): void
    {
        Http::fake([
            'cdn.ng-resource.com/*.mp3' => Http::response('ID3-binary', 200),
            'cdn.ng-resource.com/*'     => Http::response(['subtitles' => []], 200),
        ]);

        $media = $this->createMediaWithPayload();

        (new VideoTranscriberArchiveJob($media))->handle();

        $base = sprintf('videotranscriber.ai/%s', $media->id);
        // Named by meaning, not by the CDN's UUID filenames, and .json rather
        // than the .txt the CDN serves them as.
        Storage::disk('s3')->assertExists([
            $base . '/original.transcript.json',
            $base . '/original.subtitle.json',
            $base . '/optimized.transcript.json',
            $base . '/optimized.subtitle.json',
            $base . '/ai_enhanced.transcript.json',
            $base . '/ai_enhanced.subtitle.json',
            $base . '/audio.mp3',
        ]);
        $this->assertSame('ID3-binary', Storage::disk('s3')->get($base . '/audio.mp3'));
    }

    public function testEachAssetKeepsTheContentItsOwnUrlReturned(): void
    {
        Http::fake([
            'cdn.ng-resource.com/*30b06762*' => Http::response(
                ['subtitles' => [['text' => '我不相信啦。']]],
                200
            ),
            'cdn.ng-resource.com/*bf1a413d*' => Http::response(
                ['detected_language' => 'zh'],
                200
            ),
            'cdn.ng-resource.com/*' => Http::response(['blocks' => []], 200),
        ]);

        $media = $this->createMediaWithPayload();

        (new VideoTranscriberArchiveJob($media))->handle();

        $base = sprintf('videotranscriber.ai/%s', $media->id);
        $subtitle = json_decode(Storage::disk('s3')->get($base . '/ai_enhanced.subtitle.json'), true);
        $this->assertSame('我不相信啦。', $subtitle['subtitles'][0]['text']);

        // `original.subtitle_url` is the file carrying the detected language —
        // the same one the fetch job reads to pick the caption's locale.
        $origin = json_decode(Storage::disk('s3')->get($base . '/original.subtitle.json'), true);
        $this->assertSame('zh', $origin['detected_language']);
    }

    public function testOverwritesWhateverWasThereBefore(): void
    {
        Http::fake(['cdn.ng-resource.com/*' => Http::response(['blocks' => ['new']], 200)]);

        $media = $this->createMediaWithPayload();
        $path = sprintf('videotranscriber.ai/%s/ai_enhanced.transcript.json', $media->id);
        Storage::disk('s3')->put($path, 'stale');

        (new VideoTranscriberArchiveJob($media))->handle();

        $this->assertSame(['blocks' => ['new']], json_decode(Storage::disk('s3')->get($path), true));
    }

    public function testSkipsTheVersionsTheResponseDoesNotCarry(): void
    {
        $payload = $this->payload();
        unset($payload['data']['versions']['optimized'], $payload['data']['extra_data']);

        Http::fake(['cdn.ng-resource.com/*' => Http::response(['blocks' => []], 200)]);

        $media = $this->createMediaWithPayload($payload);

        (new VideoTranscriberArchiveJob($media))->handle();

        $base = sprintf('videotranscriber.ai/%s', $media->id);
        Storage::disk('s3')->assertExists($base . '/ai_enhanced.subtitle.json');
        Storage::disk('s3')->assertMissing([
            $base . '/optimized.transcript.json',
            $base . '/optimized.subtitle.json',
            $base . '/audio.mp3',
        ]);
    }

    public function testReleasesForRetryWhenAnAssetCannotBeDownloaded(): void
    {
        Http::fake([
            'cdn.ng-resource.com/*30b06762*' => Http::response('nope', 403),
            'cdn.ng-resource.com/*'          => Http::response(['blocks' => []], 200),
        ]);

        $media = $this->createMediaWithPayload();

        $job = new VideoTranscriberArchiveJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle();

        $this->assertTrue($job->job->isReleased());
        $this->assertSame(120, $job->job->releaseDelay);
        // The ones that did come down are kept: the retry overwrites them.
        Storage::disk('s3')->assertExists(sprintf('videotranscriber.ai/%s/original.transcript.json', $media->id));
    }

    public function testGivesUpOnceTheAttemptsRunOut(): void
    {
        Http::fake(['cdn.ng-resource.com/*' => Http::response('nope', 500)]);

        $media = $this->createMediaWithPayload();

        $job = new VideoTranscriberArchiveJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 5;

        $job->handle();

        $this->assertFalse($job->job->isReleased());
    }

    public function testDoesNothingWhenNoResponseWasEverArchived(): void
    {
        Http::fake(['cdn.ng-resource.com/*' => Http::response(['blocks' => []], 200)]);

        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $job = new VideoTranscriberArchiveJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle();

        // Retrying cannot conjure a response that was never stored, so the job
        // ends instead of burning its attempts.
        $this->assertFalse($job->job->isReleased());
        Http::assertNothingSent();
    }

    public function testLeavesTheMediaStatusAlone(): void
    {
        Http::fake(['cdn.ng-resource.com/*' => Http::response('nope', 500)]);

        $media = $this->createMediaWithPayload();

        (new VideoTranscriberArchiveJob($media))->handle();

        $media->refresh();
        // Archiving is a side branch: a failed download must never demote a
        // media whose captions are perfectly fine.
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->status);
    }
}
