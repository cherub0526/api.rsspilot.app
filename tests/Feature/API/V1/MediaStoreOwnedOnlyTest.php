<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use Mockery\MockInterface;
use App\Services\YoutubeService;
use Google\Service\YouTube\Video;
use Hypervel\Support\Facades\Queue;
use Google\Service\YouTube\VideoSnippet;
use App\Jobs\Media\VideoTranscriberStartJob;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesVideoTranscriberOpenApi;
use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * transcribe-owned-only 驗收：POST /v1/media 派出的 StartJob（S05、S10）。
 *
 * 送出走 openapi，legacy 的 VideoTranscriberClient 只用來給 store 查 url-info。
 *
 * @internal
 * @coversNothing
 */
class MediaStoreOwnedOnlyTest extends TestCase
{
    use RefreshDatabase;
    use FakesVideoTranscriberOpenApi;

    private const VIDEO_URL = 'https://www.youtube.com/watch?v=' . self::VIDEO_ID;

    private function fakeStoreExternals(): void
    {
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $snippet = new VideoSnippet();
            $snippet->setChannelId('UCqa');
            $snippet->setChannelTitle('QA');
            $video = new Video();
            $video->setSnippet($snippet);

            $mock->shouldReceive('getVideoIdFromUrl')->andReturn(self::VIDEO_ID);
            $mock->shouldReceive('getChannelThumbnail')->andReturn(null);
            $mock->shouldReceive('getVideoDetails')->andReturn($video);
        });
        $this->mock(VideoTranscriberClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('getUrlInfo')->andReturn([
                'code' => 100000,
                'data' => ['type' => 3, 'title' => 'T', 'audio_time' => 139, 'youtube_video_data' => ['videoInfo' => ['duration' => 139]]],
            ]);
        });
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
    }

    /** @return array{0: User, 1: Media, 2: VideoTranscriberStartJob} */
    private function addVideo(): array
    {
        Queue::fake();
        $this->useOpenApi();
        $this->fakeStoreExternals();
        /** @var User $user */
        $user = $this->fakeLogin();

        $id = $this->json('POST', route('api.v1.media.store'), ['url' => self::VIDEO_URL])->assertStatus(201)->json('id');

        $jobs = Queue::pushed(VideoTranscriberStartJob::class);
        $this->assertCount(1, $jobs, 'store dispatched one StartJob');

        return [$user, Media::query()->findOrFail($id), $jobs->first()];
    }

    public function testS05AnAddedVideoIsSubmittedAsBefore(): void
    {
        [, $media, $job] = $this->addVideo();

        $this->runJob($job);

        $this->assertAskedOpenApi('POST', '/transcriptions');
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->refresh()->status);
    }

    public function testS10AJobFromStoreIsNotManual(): void
    {
        [$user, $media, $job] = $this->addVideo();
        $media->users()->detach($user->id);

        $this->runJob($job);

        $this->assertSame([], $this->openApiRequests());
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status);
    }
}
