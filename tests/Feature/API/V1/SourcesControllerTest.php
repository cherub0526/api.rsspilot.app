<?php
// tests/Feature/API/V1/SourcesControllerTest.php
declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use Mockery\MockInterface;
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

    public function testUpdate(): void
    {
        // Need a source to build the URI — use a dummy one for the 401 check
        $tmpSource = Source::factory()->create();
        $this->json('PUT', route('api.v1.sources.update', ['sourceId' => $tmpSource->id]), ['notify' => false])
            ->assertStatus(401);

        /** @var User $user */
        $user   = $this->fakeLogin();
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
        $otherUri    = route('api.v1.sources.update', ['sourceId' => $otherSource->id]);

        $this->json('PUT', $otherUri, ['notify' => false])->assertStatus(404);
    }

    public function testDestroy(): void
    {
        $source = Source::factory()->create();
        $uri    = route('api.v1.sources.destroy', ['sourceId' => $source->id]);

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
        $otherUri    = route('api.v1.sources.destroy', ['sourceId' => $otherSource->id]);

        $this->json('DELETE', $otherUri)->assertStatus(404);
    }
}
