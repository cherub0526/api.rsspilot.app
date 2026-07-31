<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Caption;
use Hypervel\Queue\Jobs\FakeJob;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Http;
use App\Jobs\Media\VideoTranscriberFetchJob;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * @internal
 * @covers \App\Jobs\Media\VideoTranscriberFetchJob
 */
class VideoTranscriberFetchJobTest extends TestCase
{
    use RefreshDatabase;

    private function createMediaWithAudioId(string $audioId = 'audio-1'): Media
    {
        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);

        VideoTranscription::factory()->create([
            'media_id'            => $media->id,
            'url_info'            => ['code' => 100000],
            'start_transcription' => ['code' => 100000, 'data' => ['event_id' => 'event-1', 'audio_id' => $audioId]],
        ]);

        return $media;
    }

    public function testSavesCaptionAndMarksTranscribedWhenReady(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => [
                    'versions' => [
                        'original' => [
                            'status'    => 'ready',
                            'subtitles' => [
                                ['start' => '00:00:00', 'end' => '00:00:22', 'text' => " what's up everybody "],
                                ['start' => '00:01:05', 'end' => '00:01:39', 'text' => ''],
                                ['start' => '00:12:51', 'end' => '00:13:29', 'text' => 'thanks for watching'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->status);

        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertSame('ready', $record->transcription['data']['versions']['original']['status']);

        $caption = Caption::where('media_id', $media->id)->first();
        $this->assertNotNull($caption);
        $this->assertTrue((bool) $caption->primary);
        $this->assertSame(Caption::LOCAL_EN, $caption->locale);
        $this->assertSame("what's up everybody thanks for watching", $caption->text);
        $this->assertSame([], $caption->word_segments);
        // Whole-second floats round-trip through the array cast's JSON
        // encoding as integers (json_encode collapses 22.0 to 22).
        $this->assertSame([
            ['start' => 0, 'end' => 22, 'text' => "what's up everybody"],
            ['start' => 771, 'end' => 809, 'text' => 'thanks for watching'],
        ], $caption->segments);
    }

    public function testMarksTranscribeFailedWhenGetTranscriptionThrows(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::failedConnection(),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);
    }

    public function testMarksTranscribeFailedWhenGetTranscriptionReturnsNonSuccessCode(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code'    => 164001,
                'message' => 'wrong params',
                'data'    => null,
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);
    }

    public function testMarksTranscribeFailedWhenNoAudioIdIsSaved(): void
    {
        $media = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);
        VideoTranscription::factory()->create([
            'media_id'            => $media->id,
            'start_transcription' => ['code' => 100000, 'data' => []],
        ]);

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);
    }

    public function testReleasesForRetryWhenNotReadyAndUnderAttemptLimit(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['versions' => ['original' => ['status' => 'processing', 'subtitles' => []]]],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 30;

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->status);
        $this->assertTrue($job->job->isReleased());
        $this->assertSame(60, $job->job->releaseDelay);

        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertSame('processing', $record->transcription['data']['versions']['original']['status']);
    }

    public function testMarksTranscribeFailedAfterExhaustingRetryLimit(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['versions' => ['original' => ['status' => 'processing', 'subtitles' => []]]],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 60;

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);
        $this->assertFalse($job->job->isReleased());
    }

    public function testPrefersTheAiEnhancedVersionOverTheOtherOnes(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'versions' => [
                        'original' => [
                            'status'    => 'ready',
                            'subtitles' => [['start' => '00:00:01', 'end' => '00:00:09', 'text' => '我不要信啦']],
                        ],
                        'optimized' => [
                            'status'    => 'ready',
                            'subtitles' => [['start' => 1.249, 'end' => 9.092, 'text' => '我不要信啦']],
                        ],
                        'ai_enhanced' => [
                            'status'    => 'ready',
                            'subtitles' => [['start' => 1.249, 'end' => 9.092, 'text' => '我不相信啦。']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $caption = Caption::where('media_id', $media->id)->first();
        $this->assertSame('我不相信啦。', $caption->text);
        $this->assertSame([['start' => 1.249, 'end' => 9.092, 'text' => '我不相信啦。']], $caption->segments);
    }

    public function testFallsBackToOptimizedWhenAiEnhancedFailed(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'versions' => [
                        'original' => [
                            'status'    => 'ready',
                            'subtitles' => [['start' => '00:00:01', 'end' => '00:00:09', 'text' => 'original text']],
                        ],
                        'optimized' => [
                            'status'    => 'ready',
                            'subtitles' => [['start' => 1.5, 'end' => 9.25, 'text' => 'optimized text']],
                        ],
                        'ai_enhanced' => ['status' => 'failed', 'subtitles' => []],
                    ],
                ],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $caption = Caption::where('media_id', $media->id)->first();
        $this->assertSame('optimized text', $caption->text);
        $this->assertSame([['start' => 1.5, 'end' => 9.25, 'text' => 'optimized text']], $caption->segments);
    }

    public function testSkipsAReadyVersionThatCarriesNoSubtitles(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'versions' => [
                        'original' => [
                            'status'    => 'ready',
                            'subtitles' => [['start' => '00:00:01', 'end' => '00:00:09', 'text' => 'original text']],
                        ],
                        'optimized'   => ['status' => 'ready', 'subtitles' => []],
                        'ai_enhanced' => ['status' => 'ready', 'subtitles' => []],
                    ],
                ],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $caption = Caption::where('media_id', $media->id)->first();
        $this->assertSame('original text', $caption->text);
    }

    public function testWaitsForTheAiEnhancedVersionWhileItIsStillProcessing(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'versions' => [
                        'original' => [
                            'status'    => 'ready',
                            'subtitles' => [['start' => '00:00:01', 'end' => '00:00:09', 'text' => 'original text']],
                        ],
                        'optimized'   => ['status' => 'ready', 'subtitles' => []],
                        'ai_enhanced' => ['status' => 'processing', 'subtitles' => []],
                    ],
                ],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 3;

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->status);
        $this->assertTrue($job->job->isReleased());
        $this->assertNull(Caption::where('media_id', $media->id)->first());
    }

    public function testMarksTranscribeFailedWhenEveryVersionIsReadyButEmpty(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'versions' => [
                        'original'    => ['status' => 'ready', 'subtitles' => []],
                        'optimized'   => ['status' => 'ready', 'subtitles' => []],
                        'ai_enhanced' => ['status' => 'failed', 'subtitles' => []],
                    ],
                ],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);
        $this->assertFalse($job->job->isReleased());
        $this->assertNull(Caption::where('media_id', $media->id)->first());
    }

    public function testMarksTranscribeFailedWhenTheSelectedVersionOnlyHasBlankText(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'versions' => [
                        'ai_enhanced' => [
                            'status'    => 'ready',
                            'subtitles' => [['start' => 1.0, 'end' => 2.0, 'text' => '   ']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);
        $this->assertNull(Caption::where('media_id', $media->id)->first());
    }

    public function testUniqueIdIsScopedToTheMedia(): void
    {
        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);

        $this->assertSame($media->id, $job->uniqueId());
    }
}
