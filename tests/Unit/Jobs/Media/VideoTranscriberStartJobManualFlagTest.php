<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\User;
use ReflectionClass;
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
 * SR-1：「人工指定」只有 `videotranscriber:start --id` 會設。
 *
 * @internal
 * @coversNothing
 */
class VideoTranscriberStartJobManualFlagTest extends TestCase
{
    use RefreshDatabase;
    use FakesVideoTranscriberOpenApi;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->useOpenApi();
    }

    public function testAJobIsNotManualByDefault(): void
    {
        $this->assertFalse((new VideoTranscriberStartJob($this->createdMedia()))->manual);
    }

    /** 部署前就在佇列裡的 job 沒有 manual：還原後要當成排程派工，而不是一讀就拋 Error（security r1）。 */
    public function testAJobQueuedBeforeTheFlagExistedRestoresAsNotManual(): void
    {
        // 上一版的 payload 沒有 manual：從 manual = true 的 job 拿掉這個 key，
        // 再走佇列還原 job 的同一條路（不經建構子，直接 __unserialize）
        $data = (new VideoTranscriberStartJob($this->createdMedia(), true))->__serialize();
        $this->assertArrayHasKey('manual', $data);
        unset($data['manual']);

        $restored = (new ReflectionClass(VideoTranscriberStartJob::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($data);

        $this->assertFalse($restored->manual);
    }

    public function testTheScheduledRunDispatchesNonManualJobs(): void
    {
        $media = $this->createdMedia();

        $this->artisan('videotranscriber:start')->run();

        Queue::assertPushed(VideoTranscriberStartJob::class, fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $media->id && !$job->manual);
    }

    public function testIdDispatchesAManualJob(): void
    {
        $media = $this->createdMedia(owned: false);

        $this->artisan('videotranscriber:start', ['--id' => $media->id])->run();

        Queue::assertPushed(VideoTranscriberStartJob::class, fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $media->id && $job->manual);
    }

    public function testAddingAVideoDispatchesANonManualJob(): void
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
        /** @var User $user */
        $user = $this->fakeLogin();

        $this->json('POST', route('api.v1.media.store'), ['url' => 'https://www.youtube.com/watch?v=' . self::VIDEO_ID])->assertStatus(201);

        Queue::assertPushed(VideoTranscriberStartJob::class, fn (VideoTranscriberStartJob $job) => !$job->manual);
    }
}
