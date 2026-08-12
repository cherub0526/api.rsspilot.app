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
use PHPUnit\Framework\Attributes\DataProvider;
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

        // The successful payload is deliberately not persisted while the
        // column is still MEDIUMTEXT — only rejections are kept.
        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertNull($record->transcription);

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

        // No response body ever existed, so an `error` key stands in for it.
        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertArrayHasKey('error', $record->transcription);
        $this->assertNotSame('', $record->transcription['error']);
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

    public function testKeepsTheRejectedResponseSoTheFailureCanBeExplained(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code'    => 164016,
                'message' => "You've reached the daily limit. Please login and try again.",
                'data'    => null,
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);

        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertSame(164016, $record->transcription['code']);
        $this->assertNull($record->transcription['data']);
        // The audio_id the job was dispatched with must survive the overwrite,
        // otherwise a retry loses the only handle it has on the recording.
        $this->assertSame('audio-1', $record->start_transcription['data']['audio_id']);
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
        $this->assertNull($record->transcription);
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

    public function testTakesAReadyVersionEvenWhenItCarriesNoSubtitles(): void
    {
        // `ready` with nothing in it is no longer treated as a failure, so the
        // empty ai_enhanced wins over the original that does have subtitles.
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

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);
        $this->assertNull(Caption::where('media_id', $media->id)->first());
    }

    public function testDoesNotWaitForAiEnhancedWhenALowerVersionIsReady(): void
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
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->status);
        $this->assertFalse($job->job->isReleased());
        $this->assertSame('optimized text', Caption::where('media_id', $media->id)->first()->text);
    }

    public function testReleasesForRetryWhileTheRecordIsStillProcessing(): void
    {
        // A processing record carries no `versions` key at all.
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'record_id' => 'audio-1',
                    'status'    => 'processing',
                ],
            ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->status);
        $this->assertTrue($job->job->isReleased());
        $this->assertSame(60, $job->job->releaseDelay);
    }

    public function testMarksTranscribeFailedWhenEveryVersionIsReadyButEmpty(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'status'   => 'success',
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

    #[DataProvider('detectedLanguageProvider')]
    public function testStoresTheCaptionUnderTheDetectedLanguage(string $detected, string $expected): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'status'   => 'success',
                    'versions' => [
                        'original' => [
                            'status'       => 'ready',
                            'subtitle_url' => 'https://cdn.ng-resource.com/origin.txt',
                            'subtitles'    => [['start' => '00:00:01', 'end' => '00:00:09', 'text' => 'hello']],
                        ],
                    ],
                ],
            ], 200),
            'cdn.ng-resource.com/*' => Http::response(['detected_language' => $detected], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $caption = Caption::where('media_id', $media->id)->first();
        $this->assertSame($expected, $caption->locale);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function detectedLanguageProvider(): array
    {
        return [
            'traditional chinese' => ['zh', Caption::LOCAL_ZH_TW],
            'chinese with region' => ['zh-TW', Caption::LOCAL_ZH_TW],
            'english'             => ['en', Caption::LOCAL_EN],
            'anything else'       => ['ja', 'ja'],
        ];
    }

    public function testFallsBackToEnglishWhenTheLanguageCannotBeRead(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'status'   => 'success',
                    'versions' => [
                        'original' => [
                            'status'       => 'ready',
                            'subtitle_url' => 'https://cdn.ng-resource.com/origin.txt',
                            'subtitles'    => [['start' => '00:00:01', 'end' => '00:00:09', 'text' => 'hello']],
                        ],
                    ],
                ],
            ], 200),
            'cdn.ng-resource.com/*' => Http::failedConnection(),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->status);
        $this->assertSame(Caption::LOCAL_EN, Caption::where('media_id', $media->id)->first()->locale);
    }

    public function testTakesTheLanguageFromTheOriginalVersionEvenWhenAiEnhancedIsUsed(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::response([
                'code' => 100000,
                'data' => [
                    'status'   => 'success',
                    'versions' => [
                        'original' => [
                            'status'       => 'ready',
                            'subtitle_url' => 'https://cdn.ng-resource.com/origin.txt',
                            'subtitles'    => [['start' => '00:00:01', 'end' => '00:00:09', 'text' => '沒有標點']],
                        ],
                        'ai_enhanced' => [
                            'status'       => 'ready',
                            'subtitle_url' => 'https://cdn.ng-resource.com/enhanced.txt',
                            'subtitles'    => [['start' => 1.249, 'end' => 9.092, 'text' => '有標點。']],
                        ],
                    ],
                ],
            ], 200),
            'cdn.ng-resource.com/origin.txt'   => Http::response(['detected_language' => 'zh'], 200),
            'cdn.ng-resource.com/enhanced.txt' => Http::response(['subtitles' => []], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        (new VideoTranscriberFetchJob($media))->handle(new VideoTranscriberClient());

        $caption = Caption::where('media_id', $media->id)->first();
        $this->assertSame(Caption::LOCAL_ZH_TW, $caption->locale);
        $this->assertSame('有標點。', $caption->text);
    }

    public function testCarriesOnAfterTheExpiredTokenIsRefreshedAutomatically(): void
    {
        config()->set('services.videotranscriber.email', 'cherub0526@gmail.com');
        config()->set('services.videotranscriber.password', 'secret');

        Http::fake([
            'videotranscriber.ai/api/v1/auth/email/login' => Http::response([
                'code' => 100000,
                'data' => ['access_token' => 'fresh-token'],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions?*' => Http::sequence()
                ->push(['message' => 'unauthorized'], 401)
                ->push([
                    'code'    => 100000,
                    'message' => 'success',
                    'data'    => [
                        'versions' => [
                            'original' => [
                                'status'    => 'ready',
                                'subtitles' => [
                                    ['start' => '00:00:00', 'end' => '00:00:22', 'text' => 'hello'],
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);
        $job->job = new FakeJob();

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->status);
        $this->assertFalse($job->job->isReleased());
        $this->assertSame('hello', Caption::where('media_id', $media->id)->first()->text);
    }

    public function testReleasesForRetryWhenTheTokenCannotBeRefreshed(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/auth/email/login' => Http::response(
                ['code' => 100001, 'message' => 'invalid credentials'],
                200
            ),
            'videotranscriber.ai/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->status);
        $this->assertTrue($job->job->isReleased());
        $this->assertSame(300, $job->job->releaseDelay);
    }

    public function testMarksTranscribeFailedOnceTheAuthRetriesAreExhausted(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/auth/email/login' => Http::response(
                ['code' => 100001, 'message' => 'invalid credentials'],
                200
            ),
            'videotranscriber.ai/*' => Http::response(['message' => 'unauthorized'], 401),
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

    public function testUniqueIdIsScopedToTheMedia(): void
    {
        $media = $this->createMediaWithAudioId();

        $job = new VideoTranscriberFetchJob($media);

        $this->assertSame($media->id, $job->uniqueId());
    }
}
