<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Queue\Jobs\FakeJob;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Http;
use App\Jobs\Media\VideoTranscriberStartJob;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * @internal
 * @covers \App\Jobs\Media\VideoTranscriberStartJob
 */
class VideoTranscriberStartJobTest extends TestCase
{
    use RefreshDatabase;

    private function createMedia(): Media
    {
        return Media::factory()->create([
            'status'       => Media::STATUS_CREATED,
            'video_detail' => ['yt:videoId' => 'abc123'],
        ]);
    }

    public function testSuccessfullyStartsTranscriptionAndSavesBothResponses(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['type' => 3, 'title' => 'Test Video', 'audio_time' => 139],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions/start*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['event_id' => 'event-1', 'audio_id' => 'audio-1'],
            ], 200),
        ]);

        $media = $this->createMedia();

        (new VideoTranscriberStartJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->status);

        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(100000, $record->url_info['code']);
        $this->assertSame('Test Video', $record->url_info['data']['title']);
        $this->assertSame('audio-1', $record->start_transcription['data']['audio_id']);
    }

    public function testUpdatesMediaDurationFromUrlInfo(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => [
                    'type'               => 3,
                    'title'              => 'Test Video',
                    'audio_time'         => 2950,
                    'youtube_video_data' => [
                        'videoInfo' => ['duration' => 139],
                    ],
                ],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions/start*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['event_id' => 'event-1', 'audio_id' => 'audio-1'],
            ], 200),
        ]);

        $media = $this->createMedia();
        $media->fill(['duration' => 0])->save();

        (new VideoTranscriberStartJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(139, $media->duration);
    }

    public function testFallsBackToAudioTimeWhenVideoInfoDurationIsZeroOrMissing(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => [
                    'type'               => 3,
                    'title'              => 'Test Video',
                    'audio_time'         => 139,
                    'youtube_video_data' => [
                        'videoInfo' => ['duration' => 0],
                    ],
                ],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions/start*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['event_id' => 'event-1', 'audio_id' => 'audio-1'],
            ], 200),
        ]);

        $media = $this->createMedia();
        $media->fill(['duration' => 0])->save();

        (new VideoTranscriberStartJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(139, $media->duration);
    }

    public function testKeepsExistingDurationWhenUrlInfoHasNone(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['type' => 3, 'title' => 'Test Video'],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions/start*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['event_id' => 'event-1', 'audio_id' => 'audio-1'],
            ], 200),
        ]);

        $media = $this->createMedia();
        $media->fill(['duration' => 300])->save();

        (new VideoTranscriberStartJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(300, $media->duration);
    }

    public function testStartTranscriptionUsesUrlInfoDataAsPayload(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['type' => 3, 'title' => 'Test Video', 'audio_time' => 139],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions/start*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['event_id' => 'event-1', 'audio_id' => 'audio-1'],
            ], 200),
        ]);

        $media = $this->createMedia();

        (new VideoTranscriberStartJob($media))->handle(new VideoTranscriberClient());

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://videotranscriber.ai/api/v1/transcriptions/start') {
                return false;
            }

            $body = $request->data();

            return $body['path'] === 'https://www.youtube.com/watch?v=abc123'
                && $body['type'] === 3
                && $body['audio_time'] === 139
                && $body['file_name'] === 'Test Video';
        });
    }

    public function testMarksMediaAsTranscribeFailedWhenGetUrlInfoThrows(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::failedConnection(),
        ]);

        $media = $this->createMedia();

        (new VideoTranscriberStartJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);

        // No response body ever existed, so an `error` key stands in for it —
        // without it the failure is indistinguishable from a media never started.
        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertNotNull($record);
        $this->assertArrayHasKey('error', $record->url_info);
        $this->assertNotSame('', $record->url_info['error']);
        $this->assertNull($record->start_transcription);
    }

    public function testMarksMediaAsTranscribeFailedWhenGetUrlInfoReturnsNonSuccessCode(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::response([
                'code'    => 164001,
                'message' => 'wrong params',
                'data'    => null,
            ], 200),
        ]);

        $media = $this->createMedia();

        (new VideoTranscriberStartJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);

        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(164001, $record->url_info['code']);
        $this->assertNull($record->start_transcription);
    }

    public function testKeepsSavedUrlInfoWhenStartTranscriptionFails(): void
    {
        Http::fake([
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['type' => 3, 'title' => 'Test Video', 'audio_time' => 139],
            ], 200),
            'videotranscriber.ai/api/v1/transcriptions/start*' => Http::response([
                'code'    => 164016,
                'message' => "You've reached the daily limit. Please login and try again.",
                'data'    => null,
            ], 200),
        ]);

        $media = $this->createMedia();

        (new VideoTranscriberStartJob($media))->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);

        // The second updateOrCreate must add the rejection alongside the
        // url_info the first one stored, not replace the whole row.
        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(100000, $record->url_info['code']);
        $this->assertSame(164016, $record->start_transcription['code']);
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
            'videotranscriber.ai/api/v1/transcriptions/url-info*' => Http::sequence()
                ->push(['message' => 'unauthorized'], 401)
                ->push([
                    'code'    => 100000,
                    'message' => 'success',
                    'data'    => ['type' => 3, 'title' => 'Test Video', 'audio_time' => 139],
                ], 200),
            'videotranscriber.ai/api/v1/transcriptions/start*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['event_id' => 'event-1', 'audio_id' => 'audio-1'],
            ], 200),
        ]);

        $media = $this->createMedia();

        $job = new VideoTranscriberStartJob($media);
        $job->job = new FakeJob();

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->status);
        $this->assertFalse($job->job->isReleased());

        $record = VideoTranscription::where('media_id', $media->id)->first();
        $this->assertSame('audio-1', $record->start_transcription['data']['audio_id']);
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

        $media = $this->createMedia();

        $job = new VideoTranscriberStartJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 1;

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_CREATED, $media->status);
        $this->assertTrue($job->job->isReleased());
        $this->assertSame(300, $job->job->releaseDelay);
        $this->assertDatabaseCount('video_transcriptions', 0);
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

        $media = $this->createMedia();

        $job = new VideoTranscriberStartJob($media);
        $job->job = new FakeJob();
        $job->job->attempts = 12;

        $job->handle(new VideoTranscriberClient());

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->status);
        $this->assertFalse($job->job->isReleased());
    }

    public function testUniqueIdIsScopedToTheMedia(): void
    {
        $media = $this->createMedia();

        $job = new VideoTranscriberStartJob($media);

        $this->assertSame($media->id, $job->uniqueId());
    }
}
