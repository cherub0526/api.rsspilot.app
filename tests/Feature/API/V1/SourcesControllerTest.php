<?php

// tests/Feature/API/V1/SourcesControllerTest.php
declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\UserSource;
use Mockery\MockInterface;
use App\Models\Subscription;
use App\Services\YoutubeService;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class SourcesControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string PICKER_CHANNEL_ID = 'UCpickerchannel0000000001';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testIndex(): void
    {
        $uri = route('api.v1.sources.index');

        $this->json('GET', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // Empty list when no sources subscribed
        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // Subscribe to a channel
        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'title'       => 'Test Channel',
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
        ]);
        $user->sources()->attach($source->id, ['notify' => true]);

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $source->id)
            ->assertJsonPath('data.0.name', 'Test Channel')
            ->assertJsonPath('data.0.type', 'channel')
            ->assertJsonPath('data.0.notify', true);

        // Subscribe to a playlist
        $playlist = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_PLAYLIST,
            'title'       => 'Test Playlist',
            'external_id' => 'PLxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ]);
        $user->sources()->attach($playlist->id, ['notify' => false]);

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function testStoreChannel(): void
    {
        $uri = route('api.v1.sources.store');

        $this->json('POST', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // Missing required fields
        $this->json('POST', $uri)
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['url', 'type']]);

        // Invalid type
        $this->json('POST', $uri, ['url' => 'https://www.youtube.com/@test', 'type' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['type']]);

        // Invalid URL (YoutubeService returns null channel ID)
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn(null);
        });

        $this->json('POST', $uri, ['url' => 'https://invalid-url.com', 'type' => 'channel'])
            ->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.sources.invalid_url'));

        // Valid channel URL
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn('UCxxxxxxxxxxxxxxxxxxxxxx');
            $mock->shouldReceive('getChannelThumbnail')->andReturn('https://yt3.googleusercontent.com/ytc/test');
            $mock->shouldReceive('getChannelStatistics')->andReturn(['subscriber_count' => 1230000]);
        });

        Http::fake([
            'www.youtube.com/feeds/videos.xml*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Test Channel</title></feed>',
                200
            ),
        ]);

        $response = $this->json('POST', $uri, [
            'url'    => 'https://www.youtube.com/@testchannel',
            'type'   => 'channel',
            'notify' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'url', 'type', 'notify'])
            ->assertJsonPath('url', 'https://www.youtube.com/channel/UCxxxxxxxxxxxxxxxxxxxxxx')
            ->assertJsonPath('type', 'channel')
            ->assertJsonPath('notify', true)
            ->assertJsonPath('name', 'Test Channel');

        $sourceId = $response->json('id');

        $this->assertDatabaseHas('sources', [
            'id'          => $sourceId,
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
        ]);

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $sourceId,
            'notify'    => true,
        ]);

        // Re-subscribing the same source updates notify and does not duplicate
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn('UCxxxxxxxxxxxxxxxxxxxxxx');
            $mock->shouldReceive('getChannelThumbnail')->andReturn('https://yt3.googleusercontent.com/ytc/test');
            $mock->shouldReceive('getChannelStatistics')->andReturn(['subscriber_count' => 1230000]);
        });

        Http::fake([
            'www.youtube.com/feeds/videos.xml*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Test Channel</title></feed>',
                200
            ),
        ]);

        $this->json('POST', $uri, [
            'url'    => 'https://www.youtube.com/@testchannel',
            'type'   => 'channel',
            'notify' => false,
        ])->assertStatus(201);

        $this->assertEquals(1, $user->sources()->count());

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $sourceId,
            'notify'    => false,
        ]);
    }

    public function testStorePlaylist(): void
    {
        $uri = route('api.v1.sources.store');

        $this->json('POST', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        $playlistId = 'PLu96Vzt7fGU7IC5vsXXKgUWwASv5FTWMx';

        // Invalid URL (YoutubeService returns null playlist ID)
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPlaylistIdFromUrl')->andReturn(null);
        });

        $this->json('POST', $uri, ['url' => 'https://invalid-url.com', 'type' => 'playlist'])
            ->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.sources.invalid_url'));

        $this->mock(YoutubeService::class, function (MockInterface $mock) use ($playlistId) {
            $mock->shouldReceive('getPlaylistIdFromUrl')->andReturn($playlistId);
            $mock->shouldReceive('getPlaylistDetails')->with($playlistId)->andReturn([
                'title'         => 'Test Playlist',
                'channel_id'    => 'UCxxxxxx',
                'channel_title' => 'Some Channel',
                'thumbnail'     => 'https://yt3.googleusercontent.com/ytc/test-playlist',
            ]);
        });
        Http::fake([
            '*playlist_id=' . $playlistId => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Test Playlist</title></feed>',
                200
            ),
        ]);

        $response = $this->json('POST', $uri, [
            'url'    => 'https://www.youtube.com/playlist?list=' . $playlistId,
            'type'   => 'playlist',
            'notify' => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('type', 'playlist')
            ->assertJsonPath('notify', false)
            ->assertJsonPath('name', 'Test Playlist');

        $sourceId = $response->json('id');

        $this->assertDatabaseHas('sources', [
            'id'          => $sourceId,
            'type'        => Source::TYPE_YOUTUBE_PLAYLIST,
            'external_id' => $playlistId,
        ]);

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $sourceId,
            'notify'    => false,
        ]);
    }

    public function testStoreChannelLimitCheck(): void
    {
        $uri = route('api.v1.sources.store');

        // Helper to fake a successful channel resolve
        $fakeYoutube = function (string $channelId) {
            $this->mock(YoutubeService::class, function (MockInterface $mock) use ($channelId) {
                $mock->shouldReceive('getChannelIdFromUrl')->andReturn($channelId);
                $mock->shouldReceive('getChannelThumbnail')->andReturn(null);
                $mock->shouldReceive('getChannelStatistics')->andReturn([]);
            });

            Http::fake([
                'www.youtube.com/feeds/videos.xml*' => Http::response(
                    '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Channel</title></feed>',
                    200
                ),
            ]);
        };

        /** @var User $user */
        $user = $this->fakeLogin();

        // Build a plan with channel_limit = 2 and subscribe the user to it
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'channel_limit' => 2,
            'video_limit'   => 0,
        ]));
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

        // First source: under limit — succeed
        $fakeYoutube('UC00000000000000000001');
        $this->json('POST', $uri, ['url' => 'https://www.youtube.com/@ch1', 'type' => 'channel'])
            ->assertStatus(201);

        // Second source: at limit boundary — succeed (count goes 1 → 2)
        $fakeYoutube('UC00000000000000000002');
        $this->json('POST', $uri, ['url' => 'https://www.youtube.com/@ch2', 'type' => 'channel'])
            ->assertStatus(201);

        $this->assertEquals(2, $user->sources()->count());

        // Third source: count (2) >= limit (2) — should fail
        $fakeYoutube('UC00000000000000000003');
        $this->json('POST', $uri, ['url' => 'https://www.youtube.com/@ch3', 'type' => 'channel'])
            ->assertStatus(422)
            ->assertJsonPath(
                'messages.source.0',
                __('validators.controllers.sources.channel_limit_reached')
            );

        $this->assertEquals(2, $user->sources()->count());

        // Re-subscribing an existing source at the limit is an update, not a
        // new slot — should succeed without limit check
        $fakeYoutube('UC00000000000000000001');
        $this->json('POST', $uri, ['url' => 'https://www.youtube.com/@ch1', 'type' => 'channel', 'notify' => false])
            ->assertStatus(201);

        $this->assertEquals(2, $user->sources()->count());
    }

    public function testStoreChannelUnlimitedPlan(): void
    {
        $uri = route('api.v1.sources.store');

        /** @var User $user */
        $user = $this->fakeLogin();

        // channel_limit = 0 means unlimited
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'channel_limit' => 0,
            'video_limit'   => 0,
        ]));
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 0,
        ]));
        Subscription::factory()->create([
            'user_id'    => $user->id,
            'plan_id'    => $plan->id,
            'price_id'   => $price->id,
            'status'     => Subscription::STATUS_ACTIVE,
            'start_date' => now()->subDay(),
        ]);

        // Add 5 sources; all should succeed regardless of count
        foreach (range(1, 5) as $i) {
            $channelId = sprintf('UCunlimited%010d', $i);
            $this->mock(YoutubeService::class, function (MockInterface $mock) use ($channelId) {
                $mock->shouldReceive('getChannelIdFromUrl')->andReturn($channelId);
                $mock->shouldReceive('getChannelThumbnail')->andReturn(null);
                $mock->shouldReceive('getChannelStatistics')->andReturn([]);
            });
            Http::fake([
                'www.youtube.com/feeds/videos.xml*' => Http::response(
                    '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>CH</title></feed>',
                    200
                ),
            ]);
            $this->json('POST', $uri, ['url' => "https://www.youtube.com/@unlimited{$i}", 'type' => 'channel'])
                ->assertStatus(201);
        }

        $this->assertEquals(5, $user->sources()->count());
    }

    public function testUpdate(): void
    {
        // Need a source to build the URI — use a dummy one for the 401 check
        $tmpSource = Source::factory()->create();
        $this->json('PUT', route('api.v1.sources.update', ['sourceId' => $tmpSource->id]), ['notify' => false])
            ->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL]);
        $user->sources()->attach($source->id, ['notify' => true]);

        $uri = route('api.v1.sources.update', ['sourceId' => $source->id]);

        // Missing notify field
        $this->json('PUT', $uri, [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['notify']]);

        // Toggle off
        $this->json('PUT', $uri, ['notify' => false])
            ->assertStatus(200)
            ->assertJsonPath('notify', false);

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $source->id,
            'notify'    => false,
        ]);

        // Toggle back on
        $this->json('PUT', $uri, ['notify' => true])
            ->assertStatus(200)
            ->assertJsonPath('notify', true);

        // Cannot update a source the user is not subscribed to
        $otherSource = Source::factory()->create();
        $otherUri = route('api.v1.sources.update', ['sourceId' => $otherSource->id]);

        $this->json('PUT', $otherUri, ['notify' => false])->assertStatus(404);
    }

    public function testShow(): void
    {
        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'title'       => 'Test Channel',
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
            'description' => 'A great channel.',
            'metadata'    => ['subscriber_count' => 500000],
        ]);

        // 5.4 未驗證請求回傳 401
        $this->json('GET', route('api.v1.sources.show', ['sourceId' => $source->id]))
            ->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // 5.3 來源不在訂閱中且非免費時回傳 404
        $this->json('GET', route('api.v1.sources.show', ['sourceId' => $source->id]))
            ->assertStatus(404);

        // 5.1 已訂閱使用者可取得來源（200 + 欄位正確）
        $user->sources()->attach($source->id, ['notify' => true]);

        $this->json('GET', route('api.v1.sources.show', ['sourceId' => $source->id]))
            ->assertStatus(200)
            ->assertJsonPath('id', $source->id)
            ->assertJsonPath('name', 'Test Channel')
            ->assertJsonPath('type', 'channel')
            ->assertJsonPath('notify', true)
            ->assertJsonPath('url', 'https://www.youtube.com/channel/UCxxxxxxxxxxxxxxxxxxxxxx')
            ->assertJsonPath('description', 'A great channel.')
            ->assertJsonPath('subscriber_count', 500000);

        // 5.2 已驗證使用者可取得免費來源（無需訂閱）
        $freeSource = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'title'       => 'Free Channel',
            'external_id' => 'UCfreexxxxxxxxxxxxxxxx',
            'free'        => true,
        ]);

        $this->json('GET', route('api.v1.sources.show', ['sourceId' => $freeSource->id]))
            ->assertStatus(200)
            ->assertJsonPath('id', $freeSource->id)
            ->assertJsonPath('type', 'channel');

        // 5.5 sourceId 格式無效時回傳 404
        $this->json('GET', '/api/v1/sources/not-a-valid-ulid')
            ->assertStatus(404);
    }

    public function testDestroy(): void
    {
        $source = Source::factory()->create();
        $uri = route('api.v1.sources.destroy', ['sourceId' => $source->id]);

        $this->json('DELETE', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();
        $user->sources()->attach($source->id, ['notify' => true]);

        $this->json('DELETE', $uri)->assertStatus(200);

        $this->assertDatabaseMissing('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $source->id,
        ]);

        // The shared sources record itself still exists
        $this->assertDatabaseHas('sources', ['id' => $source->id]);

        // Cannot delete a source the user is not subscribed to
        $otherSource = Source::factory()->create();
        $otherUri = route('api.v1.sources.destroy', ['sourceId' => $otherSource->id]);

        $this->json('DELETE', $otherUri)->assertStatus(404);
    }

    // ================================================================
    // source-video-picker：訂閱時只加入勾選的影片
    // ================================================================

    public function testS02StoreAddsOnlySelectedVideosAndSetsSyncedAt(): void
    {
        $now = Carbon::parse('2026-11-02 10:00:00');
        Carbon::setTestNow($now);

        /** @var User $user */
        $user = $this->fakeLogin();
        $this->fakePickerChannel(['videoAAAAAA', 'videoBBBBBB', 'videoCCCCCC']);

        $response = $this->json('POST', route('api.v1.sources.store'), [
            'url'       => 'https://www.youtube.com/@picker',
            'type'      => 'channel',
            'video_ids' => ['videoAAAAAA', 'videoCCCCCC'],
        ])->assertStatus(201);

        $this->assertEqualsCanonicalizing(
            ['yt:video:videoAAAAAA', 'yt:video:videoCCCCCC'],
            $user->media()->pluck('resource_id')->all()
        );
        $this->assertDatabaseHas('media', [
            'resource_id' => 'yt:video:videoAAAAAA',
            'source_id'   => $response->json('id'),
            'status'      => Media::STATUS_CREATED,
        ]);
        $this->assertTrue($this->pickerSyncedAt($user, $response->json('id'))->equalTo($now));
    }

    public function testS03StoreWithoutVideoIdsAddsNothing(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->fakePickerChannel(['videoAAAAAA', 'videoBBBBBB']);

        $response = $this->json('POST', route('api.v1.sources.store'), [
            'url'  => 'https://www.youtube.com/@picker',
            'type' => 'channel',
        ])->assertStatus(201);

        $this->assertDatabaseHas('user_sources', ['user_id' => $user->id, 'source_id' => $response->json('id')]);
        $this->assertSame(0, $user->media()->count());
    }

    /**
     * 訂閱當下 RSS 裡沒勾的影片，之後的同步不能因為「media 是同步才建的」就當成新影片補進來。
     */
    public function testS06VideosAlreadyInRssAtSubscribeAreNotAddedBySync(): void
    {
        $t0 = Carbon::parse('2026-11-02 10:00:00');
        Carbon::setTestNow($t0);

        /** @var User $user */
        $user = $this->fakeLogin();
        $this->fakePickerChannel(['videoAAAAAA', 'videoBBBBBB']);

        $this->json('POST', route('api.v1.sources.store'), [
            'url'       => 'https://www.youtube.com/@picker',
            'type'      => 'channel',
            'video_ids' => ['videoAAAAAA'],
        ])->assertStatus(201);

        Carbon::setTestNow($t0->copy()->addHour());
        $this->artisan('sources:sync')->assertExitCode(0);

        $this->assertSame(['yt:video:videoAAAAAA'], $user->media()->pluck('resource_id')->all());
    }

    public function testS04StoreRejectsVideoNotInSource(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->fakePickerChannel(['videoAAAAAA']);

        $this->json('POST', route('api.v1.sources.store'), [
            'url'       => 'https://www.youtube.com/@picker',
            'type'      => 'channel',
            'video_ids' => ['videoAAAAAA', 'notInFeed01'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('messages.video_ids.0', __('validators.controllers.sources.video_not_in_source'));

        $this->assertDatabaseCount('user_sources', 0);
        $this->assertSame(0, $user->media()->count());
    }

    /**
     * 預覽到送出之間 RSS 可能已經更新；已是該來源既有 media 的影片照樣接受。
     */
    public function testStoreAcceptsExistingSourceMediaThatLeftTheFeed(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->fakePickerChannel(['videoAAAAAA']);

        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => self::PICKER_CHANNEL_ID,
            'metadata'    => ['subscriber_count' => 10],
        ]);
        $old = Media::factory()->create([
            'type'        => Media::TYPE_YOUTUBE,
            'resource_id' => 'yt:video:oldVideo001',
            'source_id'   => $source->id,
        ]);

        $this->json('POST', route('api.v1.sources.store'), [
            'url'       => 'https://www.youtube.com/@picker',
            'type'      => 'channel',
            'video_ids' => ['oldVideo001'],
        ])->assertStatus(201);

        $this->assertSame([$old->id], $user->media()->pluck('media.id')->all());
    }

    public function testS05StoreRejectsSelectionOverRemainingQuota(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->subscribeUserToVideoLimit($user, 2);
        $user->media()->attach(Media::factory()->create(['status' => Media::STATUS_READY])->id);
        $this->fakePickerChannel(['videoAAAAAA', 'videoBBBBBB']);

        $this->json('POST', route('api.v1.sources.store'), [
            'url'       => 'https://www.youtube.com/@picker',
            'type'      => 'channel',
            'video_ids' => ['videoAAAAAA', 'videoBBBBBB'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('messages.video_ids.0', __('validators.controllers.media.video_limit_reached'));

        $this->assertDatabaseCount('user_sources', 0);
        $this->assertSame(1, $user->media()->count());
    }

    /**
     * 已在影片庫的影片不佔額度，扣掉之後沒超過就放行。
     */
    public function testS05VideosAlreadyInLibraryDoNotCountAgainstQuota(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->subscribeUserToVideoLimit($user, 2);
        $owned = Media::factory()->create([
            'type'        => Media::TYPE_YOUTUBE,
            'resource_id' => 'yt:video:videoAAAAAA',
            'status'      => Media::STATUS_READY,
        ]);
        $user->media()->attach($owned->id);
        $this->fakePickerChannel(['videoAAAAAA', 'videoBBBBBB']);

        $this->json('POST', route('api.v1.sources.store'), [
            'url'       => 'https://www.youtube.com/@picker',
            'type'      => 'channel',
            'video_ids' => ['videoAAAAAA', 'videoBBBBBB'],
        ])->assertStatus(201);

        $this->assertSame(2, $user->media()->count());
    }

    public function testS09ResubmittingSubscribedSourceKeepsSyncedAt(): void
    {
        $t1 = Carbon::parse('2026-11-01 08:00:00');
        Carbon::setTestNow($t1);

        /** @var User $user */
        $user = $this->fakeLogin();
        $this->fakePickerChannel(['videoAAAAAA', 'videoBBBBBB']);

        $sourceId = $this->json('POST', route('api.v1.sources.store'), [
            'url'  => 'https://www.youtube.com/@picker',
            'type' => 'channel',
        ])->assertStatus(201)->json('id');

        Carbon::setTestNow($t1->copy()->addDay());

        $this->json('POST', route('api.v1.sources.store'), [
            'url'       => 'https://www.youtube.com/@picker',
            'type'      => 'channel',
            'notify'    => false,
            'video_ids' => ['videoBBBBBB'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('notify', false);

        $this->assertSame(['yt:video:videoBBBBBB'], $user->media()->pluck('resource_id')->all());
        $this->assertTrue($this->pickerSyncedAt($user, $sourceId)->equalTo($t1));
    }

    public function testStoreRejectsMalformedVideoIds(): void
    {
        $this->fakeLogin();

        $this->json('POST', route('api.v1.sources.store'), [
            'url'       => 'https://www.youtube.com/@picker',
            'type'      => 'channel',
            'video_ids' => ['yt:video:videoAAAAAA'],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['video_ids.0']]);
    }

    /**
     * @param list<string> $videoIds
     */
    private function fakePickerChannel(array $videoIds): void
    {
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn(self::PICKER_CHANNEL_ID);
            $mock->shouldReceive('getChannelThumbnail')->andReturn(null);
            $mock->shouldReceive('getChannelStatistics')->andReturn(['subscriber_count' => 10]);
        });

        $entries = '';
        foreach ($videoIds as $i => $videoId) {
            $day = sprintf('%02d', $i + 1);
            $entries .= "<entry><id>yt:video:{$videoId}</id><yt:videoId>{$videoId}</yt:videoId>"
                . "<title>影片 {$videoId}</title><published>2026-10-{$day}T10:00:00+00:00</published></entry>";
        }

        Http::fake([
            '*channel_id=' . self::PICKER_CHANNEL_ID => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" '
                . 'xmlns="http://www.w3.org/2005/Atom"><title>Picker</title>' . $entries . '</feed>',
                200
            ),
        ]);
    }

    private function subscribeUserToVideoLimit(User $user, int $videoLimit): void
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['video_limit' => $videoLimit, 'channel_limit' => 0]));
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

    private function pickerSyncedAt(User $user, string $sourceId): Carbon
    {
        $syncedAt = UserSource::query()
            ->where('user_id', $user->id)
            ->where('source_id', $sourceId)
            ->firstOrFail()
            ->synced_at;

        $this->assertInstanceOf(Carbon::class, $syncedAt);

        return $syncedAt;
    }
}
