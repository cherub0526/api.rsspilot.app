<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Sources;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use Mockery\MockInterface;
use App\Models\Subscription;
use App\Services\YoutubeService;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * source-video-picker：加入來源前先預覽 RSS 影片清單。
 *
 * @internal
 * @coversNothing
 */
class PreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string CHANNEL_ID = 'UCpreviewchannel000000001';

    public function testPreviewRequiresLogin(): void
    {
        $this->json('GET', route('api.v1.sources.preview.index'), ['url' => 'https://www.youtube.com/@x', 'type' => 'channel'])
            ->assertStatus(401);
    }

    public function testS01PreviewListsRssVideosAndMarksThoseInLibrary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->fakeChannel();

        $owned = Media::factory()->create([
            'type'        => Media::TYPE_YOUTUBE,
            'resource_id' => 'yt:video:videoBBBBBB',
        ]);
        $user->media()->attach($owned->id);

        $response = $this->json('GET', route('api.v1.sources.preview.index'), [
            'url'  => 'https://www.youtube.com/@preview',
            'type' => 'channel',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('source.title', 'Preview Channel')
            ->assertJsonPath('source.type', 'channel')
            ->assertJsonPath('source.thumbnail', 'https://yt3.googleusercontent.com/preview')
            ->assertJsonCount(3, 'videos')
            // 依發布時間新到舊
            ->assertJsonPath('videos.0.video_id', 'videoCCCCCC')
            ->assertJsonPath('videos.1.video_id', 'videoBBBBBB')
            ->assertJsonPath('videos.2.video_id', 'videoAAAAAA')
            ->assertJsonPath('videos.0.title', '影片 videoCCCCCC')
            ->assertJsonPath('videos.0.thumbnail', 'https://i.ytimg.com/vi/videoCCCCCC/hqdefault.jpg')
            ->assertJsonPath('videos.0.published_at', '2026-10-03T10:00:00+00:00')
            ->assertJsonPath('videos.0.in_library', false)
            ->assertJsonPath('videos.1.in_library', true)
            ->assertJsonPath('videos.2.in_library', false)
            ->assertJsonPath('quota.limit', null)
            ->assertJsonPath('quota.remaining', null);

        // 預覽不寫資料：不建來源、不建 media
        $this->assertDatabaseCount('sources', 0);
        $this->assertDatabaseCount('media', 1);
    }

    public function testPreviewReportsRemainingQuota(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->subscribeToPlan($user, videoLimit: 5);
        $user->media()->attach(Media::factory()->create(['status' => Media::STATUS_READY])->id);
        $this->fakeChannel();

        $this->json('GET', route('api.v1.sources.preview.index'), [
            'url'  => 'https://www.youtube.com/@preview',
            'type' => 'channel',
        ])
            ->assertStatus(200)
            ->assertJsonPath('quota.limit', 5)
            ->assertJsonPath('quota.remaining', 4);
    }

    public function testPreviewPlaylist(): void
    {
        $this->fakeLogin();
        $playlistId = 'PLpreview0000000000000000000000001';

        $this->mock(YoutubeService::class, function (MockInterface $mock) use ($playlistId) {
            $mock->shouldReceive('getPlaylistIdFromUrl')->andReturn($playlistId);
            $mock->shouldReceive('getPlaylistDetails')->with($playlistId)->andReturn([
                'title'         => 'Preview Playlist',
                'channel_id'    => 'UCxxxxxx',
                'channel_title' => 'Some Channel',
                'thumbnail'     => 'https://i.ytimg.com/playlist.jpg',
            ]);
        });
        Http::fake([
            '*playlist_id=' . $playlistId => Http::response($this->feed(['videoAAAAAA' => '2026-10-01T10:00:00+00:00']), 200),
        ]);

        $this->json('GET', route('api.v1.sources.preview.index'), [
            'url'  => 'https://www.youtube.com/playlist?list=' . $playlistId,
            'type' => 'playlist',
        ])
            ->assertStatus(200)
            ->assertJsonPath('source.title', 'Preview Playlist')
            ->assertJsonPath('source.type', 'playlist')
            ->assertJsonCount(1, 'videos')
            ->assertJsonPath('videos.0.video_id', 'videoAAAAAA');
    }

    public function testPreviewValidatesParams(): void
    {
        $this->fakeLogin();

        $this->json('GET', route('api.v1.sources.preview.index'))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['url', 'type']]);
    }

    public function testPreviewRejectsUnresolvableUrl(): void
    {
        $this->fakeLogin();
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn(null);
        });

        $this->json('GET', route('api.v1.sources.preview.index'), ['url' => 'https://example.com', 'type' => 'channel'])
            ->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.sources.invalid_url'));
    }

    public function testPreviewRejectsWhenRssCannotBeFetched(): void
    {
        $this->fakeLogin();
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn(self::CHANNEL_ID);
        });
        Http::fake(['*' => Http::response('', 404)]);

        $this->json('GET', route('api.v1.sources.preview.index'), ['url' => 'https://www.youtube.com/@x', 'type' => 'channel'])
            ->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.sources.invalid_url'));
    }

    private function fakeChannel(): void
    {
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn(self::CHANNEL_ID);
            $mock->shouldReceive('getChannelThumbnail')->andReturn('https://yt3.googleusercontent.com/preview');
        });

        Http::fake([
            '*channel_id=' . self::CHANNEL_ID => Http::response($this->feed([
                'videoAAAAAA' => '2026-10-01T10:00:00+00:00',
                'videoCCCCCC' => '2026-10-03T10:00:00+00:00',
                'videoBBBBBB' => '2026-10-02T10:00:00+00:00',
            ]), 200),
        ]);
    }

    /**
     * @param array<string, string> $videos videoId => published
     */
    private function feed(array $videos): string
    {
        $entries = '';

        foreach ($videos as $videoId => $published) {
            $entries .= <<<XML
                  <entry>
                    <id>yt:video:{$videoId}</id>
                    <yt:videoId>{$videoId}</yt:videoId>
                    <title>影片 {$videoId}</title>
                    <published>{$published}</published>
                    <media:group>
                      <media:thumbnail url="https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg"/>
                    </media:group>
                  </entry>

                XML;
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"
                  xmlns:media="http://search.yahoo.com/mrss/"
                  xmlns="http://www.w3.org/2005/Atom">
              <title>Preview Channel</title>
            {$entries}</feed>
            XML;
    }

    private function subscribeToPlan(User $user, int $videoLimit): void
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['video_limit' => $videoLimit]));
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 9.99,
        ]));
        Subscription::factory()->create([
            'user_id'    => $user->id,
            'plan_id'    => $plan->id,
            'price_id'   => $price->id,
            'status'     => Subscription::STATUS_ACTIVE,
            'start_date' => now()->subDay(),
        ]);
    }
}
