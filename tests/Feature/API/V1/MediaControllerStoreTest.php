<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use RuntimeException;
use App\Models\Source;
use Mockery\MockInterface;
use App\Services\YoutubeService;
use Google\Service\YouTube\Video;
use Hypervel\Support\Facades\Queue;
use Google\Service\YouTube\VideoSnippet;
use App\Jobs\Media\VideoTranscriberStartJob;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\VideoTranscriber\VideoTranscriberClient;

/**
 * POST /v1/media —— 使用者貼 YouTube 影片網址加入自己的影片庫。
 *
 * @internal
 * @coversNothing
 */
class MediaControllerStoreTest extends TestCase
{
    use RefreshDatabase;

    private const VIDEO_ID = 'uXHNRFHWDnM';

    private const RESOURCE_ID = 'yt:video:uXHNRFHWDnM';

    private const URL = 'https://www.youtube.com/watch?v=uXHNRFHWDnM';

    /**
     * getUrlInfo 的成功回應，形狀取自 VideoTranscriberClientTest 的 fixture。
     */
    private function urlInfoResponse(): array
    {
        return [
            'code'    => 100000,
            'message' => 'success',
            'data'    => [
                'type'               => 3,
                'title'              => '8 Functions you might not know',
                'audio_time'         => 139,
                'thumbnail_url'      => 'https://i.ytimg.com/vi/uXHNRFHWDnM/hqdefault.jpg',
                'youtube_video_data' => [
                    'videoId'   => self::VIDEO_ID,
                    'videoInfo' => [
                        'name'       => '8 Functions you might not know',
                        'duration'   => 139,
                        'author'     => 'MB212',
                        'channel_id' => 'UCPblPw-MSsRVMQyz4BiBXpA',
                    ],
                ],
                'youtube_has_subtitles' => false,
            ],
        ];
    }

    /**
     * @param null|array $urlInfo null 代表 getUrlInfo 會拋例外
     */
    private function fakeExternals(?array $urlInfo = null, bool $withVideoDetails = true): void
    {
        $this->mock(YoutubeService::class, function (MockInterface $mock) use ($withVideoDetails) {
            $mock->shouldReceive('getVideoIdFromUrl')->andReturn(self::VIDEO_ID);

            if (!$withVideoDetails) {
                $mock->shouldReceive('getVideoDetails')->andReturn(null);

                return;
            }

            $snippet = new VideoSnippet();
            $snippet->setDescription('影片描述');
            $snippet->setPublishedAt('2026-03-01T10:00:00Z');

            $video = new Video();
            $video->setSnippet($snippet);

            $mock->shouldReceive('getVideoDetails')->andReturn($video);
        });

        $this->mock(VideoTranscriberClient::class, function (MockInterface $mock) use ($urlInfo) {
            if ($urlInfo === null) {
                $mock->shouldReceive('getUrlInfo')->andThrow(new RuntimeException('upstream down'));

                return;
            }

            $mock->shouldReceive('getUrlInfo')->andReturn($urlInfo);
        });
    }

    /**
     * 沒有訂閱的使用者吃「月費 0 元」的方案。
     */
    private function createFreePlan(int $videoLimit): Plan
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'       => 'Free',
            'video_limit' => $videoLimit,
            'status'      => Plan::STATUS_ACTIVE,
        ]));

        // withoutEvents 會連 HasUlids 的 id 產生一起關掉，所以走 factory 拿 id。
        Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 0,
        ]));

        return $plan;
    }

    private function addVideo(string $url = self::URL)
    {
        return $this->json('POST', route('api.v1.media.store'), ['url' => $url]);
    }

    // ================================================================

    public function testStoreRequiresAuth(): void
    {
        $this->json('POST', route('api.v1.media.store'), ['url' => self::URL])
            ->assertStatus(401);
    }

    public function testStoreValidatesUrlPresence(): void
    {
        $this->fakeLogin();

        $this->json('POST', route('api.v1.media.store'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['url']]);
    }

    /**
     * 不是 YouTube 影片網址 → 422，而且完全不碰外部服務。
     */
    public function testStoreRejectsNonVideoUrlWithoutCallingExternals(): void
    {
        $this->fakeLogin();

        $this->mock(VideoTranscriberClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getUrlInfo');
        });

        $this->addVideo('https://www.youtube.com/@googledevelopers')
            ->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.media.invalid_url'));

        $this->assertDatabaseCount('media', 0);
    }

    /**
     * 正常流程：建立 media、掛到使用者、送去轉錄。
     */
    public function testStoreCreatesMediaAndAttachesToUser(): void
    {
        Queue::fake();

        /** @var User $user */
        $user = $this->fakeLogin();
        $this->fakeExternals($this->urlInfoResponse());

        $response = $this->addVideo()->assertStatus(201);

        $mediaId = $response->json('id');

        $this->assertDatabaseHas('media', [
            'id'          => $mediaId,
            'resource_id' => self::RESOURCE_ID,
            'type'        => Media::TYPE_YOUTUBE,
            'status'      => Media::STATUS_CREATED,
            'source_id'   => null,
            'duration'    => 139,
        ]);

        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $mediaId,
        ]);

        $media = Media::find($mediaId);

        // 下游一律從 video_detail['yt:videoId'] 取影片 ID
        $this->assertSame(self::VIDEO_ID, $media->video_detail['yt:videoId']);
        $this->assertSame('UCPblPw-MSsRVMQyz4BiBXpA', $media->video_detail['yt:channelId']);

        // getVideoDetails 補上的欄位
        $this->assertSame('影片描述', $media->description);
        $this->assertNotNull($media->published_at);

        Queue::assertPushed(VideoTranscriberStartJob::class);
    }

    /**
     * getUrlInfo 回非成功碼 → 422，不建立任何資料。
     */
    public function testStoreRejectsWhenUrlInfoFails(): void
    {
        Queue::fake();
        $this->fakeLogin();
        $this->fakeExternals(['code' => 164001, 'message' => 'invalid url']);

        $this->addVideo()
            ->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.media.invalid_url'));

        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseCount('userables', 0);
        Queue::assertNotPushed(VideoTranscriberStartJob::class);
    }

    /**
     * getUrlInfo 整個炸掉（連線失敗等）→ 一樣是 422，不會變成 500。
     */
    public function testStoreRejectsWhenUrlInfoThrows(): void
    {
        $this->fakeLogin();
        $this->fakeExternals(null);

        $this->addVideo()->assertStatus(422);

        $this->assertDatabaseCount('media', 0);
    }

    /**
     * getVideoDetails 拿不到資料 → 仍然建立成功，只是少了描述與發布時間。
     */
    public function testStoreSucceedsWhenVideoDetailsUnavailable(): void
    {
        Queue::fake();
        $this->fakeLogin();
        $this->fakeExternals($this->urlInfoResponse(), withVideoDetails: false);

        $mediaId = $this->addVideo()->assertStatus(201)->json('id');

        $media = Media::find($mediaId);

        $this->assertSame('', $media->description);
        $this->assertNull($media->published_at);
        $this->assertSame('8 Functions you might not know', $media->title);
    }

    /**
     * 影片已經在資料庫（別人訂閱的頻道帶進來的）→ 重用該列，不重複建立、不重跑轉錄。
     */
    public function testStoreReusesExistingMediaWithoutDispatching(): void
    {
        Queue::fake();

        /** @var User $user */
        $user = $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $existing = Media::factory()->create([
            'source_id'    => $source->id,
            'resource_id'  => self::RESOURCE_ID,
            'status'       => Media::STATUS_READY,
            'video_detail' => ['yt:videoId' => self::VIDEO_ID],
        ]);

        $this->mock(VideoTranscriberClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getUrlInfo');
        });
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getVideoIdFromUrl')->andReturn(self::VIDEO_ID);
            $mock->shouldNotReceive('getVideoDetails');
        });

        $this->addVideo()
            ->assertStatus(201)
            ->assertJsonPath('id', $existing->id);

        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $existing->id,
        ]);

        Queue::assertNotPushed(VideoTranscriberStartJob::class);
    }

    /**
     * 已經在自己影片庫裡的影片再貼一次 → no-op，額度就算滿了也放行。
     */
    public function testStoreIsNoOpForAlreadyOwnedMediaEvenAtQuota(): void
    {
        Queue::fake();

        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(1);

        $existing = Media::factory()->create([
            'source_id'   => null,
            'resource_id' => self::RESOURCE_ID,
        ]);
        $user->media()->attach($existing->id);

        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getVideoIdFromUrl')->andReturn(self::VIDEO_ID);
        });

        $this->addVideo()
            ->assertStatus(201)
            ->assertJsonPath('id', $existing->id);

        $this->assertDatabaseCount('userables', 1);
    }

    /**
     * 30 天內的影片額度已滿 → 422，跟 channel_limit 同型。
     */
    public function testStoreRejectsWhenVideoLimitReached(): void
    {
        Queue::fake();

        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(2);

        $user->media()->attach(Media::factory()->create()->id);
        $user->media()->attach(Media::factory()->create()->id);

        $this->fakeExternals($this->urlInfoResponse());

        $this->addVideo()
            ->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.media.video_limit_reached'));

        $this->assertDatabaseMissing('media', ['resource_id' => self::RESOURCE_ID]);
        Queue::assertNotPushed(VideoTranscriberStartJob::class);
    }

    /**
     * 30 天前加入的影片不佔額度（滾動窗口）。
     */
    public function testStoreIgnoresUsageOlderThan30Days(): void
    {
        Queue::fake();

        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(1);

        $old = Media::factory()->create();
        $user->media()->attach($old->id);
        $user->media()->updateExistingPivot($old->id, ['created_at' => now()->subDays(45)]);

        $this->fakeExternals($this->urlInfoResponse());

        $this->addVideo()->assertStatus(201);

        $this->assertDatabaseHas('media', ['resource_id' => self::RESOURCE_ID]);
    }

    /**
     * video_limit = 0 → 不限制。
     */
    public function testStoreIsUnlimitedWhenVideoLimitIsZero(): void
    {
        Queue::fake();

        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(0);

        foreach (range(1, 3) as $ignored) {
            $user->media()->attach(Media::factory()->create()->id);
        }

        $this->fakeExternals($this->urlInfoResponse());

        $this->addVideo()->assertStatus(201);
    }
}
