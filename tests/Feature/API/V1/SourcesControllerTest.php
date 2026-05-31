<?php

// tests/Feature/API/V1/SourcesControllerTest.php
declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Source;
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
}
