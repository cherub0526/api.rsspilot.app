<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use App\Models\Rss;
use Tests\TestCase;
use App\Models\User;
use App\Jobs\Rss\SyncJob;
use Mockery\MockInterface;
use App\Services\YoutubeService;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Queue;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class RSSControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testIndex()
    {
        $uri = route('api.v1.rss.index');

        // Unauthenticated
        $this->json('GET', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // Missing 'type' parameter
        $this->json('GET', $uri)->assertStatus(422)
            ->assertJsonStructure(['messages' => ['type']]);

        // Create some test data
        $youtubeRss = Rss::factory()->create(['type' => Rss::TYPE_YOUTUBE]);
        $user->rss()->attach($youtubeRss->id);

        // Get only youtube feeds
        $response = $this->json('GET', $uri, ['type' => Rss::TYPE_YOUTUBE]);
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $youtubeRss->id)
            ->assertJsonPath('data.0.type', Rss::TYPE_YOUTUBE);

        // Test pagination
        Rss::factory()->count(5)->create(['type' => Rss::TYPE_YOUTUBE])->each(function ($rss) use ($user) {
            $user->rss()->attach($rss->id);
        });

        $this->json('GET', $uri, ['type' => Rss::TYPE_YOUTUBE, 'limit' => 2])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function testStore()
    {
        $uri = route('api.v1.rss.store');

        // Unauthenticated
        $this->json('POST', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // Missing parameters
        $this->json('POST', $uri)->assertStatus(422)
            ->assertJsonStructure(['messages' => ['type', 'url']]);

        // Missing url
        $params = ['type' => Rss::TYPE_YOUTUBE];
        $this->json('POST', $uri, $params)->assertStatus(422)
            ->assertJsonStructure(['messages' => ['url']]);

        // Invalid URL (cannot be parsed as XML)
        $params['url'] = 'https://invalid-url-for-testing.com';
        $this->json('POST', $uri, $params)->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.rss.invalid_url'));

        Queue::fake();

        // --- Channel URL ---
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPlaylistIdFromUrl')->andReturn(null);
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn('UCAuUUnT6oDeKwE6v1NGQxug');
        });

        Http::fake([
            'www.youtube.com/feeds/videos.xml*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Google Developers</title><id>yt:channel:UCAuUUnT6oDeKwE6v1NGQxug</id></feed>',
                200
            ),
        ]);

        $params['url'] = 'https://www.youtube.com/@googledevelopers';
        $response = $this->json('POST', $uri, $params);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'type', 'list_id', 'url', 'title'])
            ->assertJsonPath('url', 'https://www.youtube.com/channel/UCAuUUnT6oDeKwE6v1NGQxug');

        Queue::assertPushedOn('rss.sync', SyncJob::class);

        $createdRssId = $response->json('id');

        $this->assertDatabaseHas('rss', [
            'id'      => $createdRssId,
            'type'    => Rss::TYPE_YOUTUBE,
            'list_id' => 'UCAuUUnT6oDeKwE6v1NGQxug',
            'url'     => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCAuUUnT6oDeKwE6v1NGQxug',
        ]);

        $this->assertDatabaseHas('userables', [
            'user_id' => $user->id,
            'rss_id'  => $createdRssId,
        ]);

        // --- Playlist URL ---
        Queue::fake();

        $playlistId = 'PLu96Vzt7fGU7IC5vsXXKgUWwASv5FTWMx';

        $this->mock(YoutubeService::class, function (MockInterface $mock) use ($playlistId) {
            $mock->shouldReceive('getPlaylistIdFromUrl')->andReturn($playlistId);
            $mock->shouldReceive('getPlaylistDetails')->with($playlistId)->andReturn([
                'title'         => 'Test Playlist',
                'channel_id'    => 'UCxxxxxx',
                'channel_title' => 'Test Channel',
            ]);
        });

        $params['url'] = 'https://www.youtube.com/playlist?list=' . $playlistId;
        $response = $this->json('POST', $uri, $params);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'type', 'list_id', 'url', 'title'])
            ->assertJsonPath('list_id', $playlistId)
            ->assertJsonPath('title', 'Test Playlist')
            ->assertJsonPath('url', 'https://www.youtube.com/playlist?list=' . $playlistId);

        Queue::assertPushedOn('rss.sync', SyncJob::class);

        $playlistRssId = $response->json('id');

        $this->assertDatabaseHas('rss', [
            'id'      => $playlistRssId,
            'type'    => Rss::TYPE_YOUTUBE,
            'list_id' => $playlistId,
            'url'     => 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $playlistId,
        ]);

        $this->assertDatabaseHas('userables', [
            'user_id' => $user->id,
            'rss_id'  => $playlistRssId,
        ]);
    }

    public function testDestroy()
    {
        $rss = Rss::factory()->create();
        $uri = route('api.v1.rss.destroy', ['rssId' => $rss->id]);

        // Unauthenticated
        $this->json('DELETE', $uri)->assertStatus(401);

        $user = $this->fakeLogin();
        $user->rss()->attach([$rss->id]);

        // Delete own RSS feed
        $this->json('DELETE', $uri)->assertStatus(200);

        $this->assertDatabaseMissing('userables', [
            'user_id' => $user->id,
            'rss_id'  => $rss->id,
        ]);

        $otherRss = Rss::factory()->create();

        $otherUserUri = route('api.v1.rss.destroy', ['rssId' => $otherRss->id]);

        // Try to delete another user's RSS feed
        $this->json('DELETE', $otherUserUri)->assertStatus(404);

        $otherUser = $this->fakeLogin();
        $otherUser->rss()->attach($otherRss->id);

        // The RSS entry itself should still exist
        $this->assertDatabaseHas('rss', ['id' => $rss->id]);

        // The other user's subscription should be unaffected
        $this->assertDatabaseHas('userables', [
            'user_id' => $otherUser->id,
            'rss_id'  => $otherRss->id,
        ]);
    }
}
