<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class PopulariesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testIndexRequiresAuth(): void
    {
        $this->json('GET', route('api.v1.popularies.index'))->assertStatus(401);
    }

    public function testIndexOnlyReturnsFreeSources(): void
    {
        $this->fakeLogin();

        $free = Source::factory()->create(['free' => true, 'type' => Source::TYPE_YOUTUBE_CHANNEL]);
        $paid = Source::factory()->create(['free' => false, 'type' => Source::TYPE_YOUTUBE_CHANNEL]);

        $response = $this->json('GET', route('api.v1.popularies.index'))->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($free->id));
        $this->assertFalse($ids->contains($paid->id));
    }

    public function testIndexFiltersByType(): void
    {
        $this->fakeLogin();

        $channel = Source::factory()->create(['free' => true, 'type' => Source::TYPE_YOUTUBE_CHANNEL]);
        $playlist = Source::factory()->create(['free' => true, 'type' => Source::TYPE_YOUTUBE_PLAYLIST]);

        $response = $this->json('GET', route('api.v1.popularies.index', ['type' => 'channel']))->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($channel->id));
        $this->assertFalse($ids->contains($playlist->id));
    }

    public function testIndexIncludesVideoCount(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => true, 'type' => Source::TYPE_YOUTUBE_CHANNEL]);
        Media::factory()->count(3)->create(['source_id' => $source->id]);

        $response = $this->json('GET', route('api.v1.popularies.index'))->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('id', $source->id);
        $this->assertNotNull($entry);
        $this->assertEquals(3, $entry['video_count']);
    }
}
