<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Sources;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class MediasControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testIndexRequiresAuth(): void
    {
        $source = Source::factory()->create(['status' => Source::STATUS_ACTIVE]);

        $this->json('GET', route('api.v1.sources.medias.index', ['sourceId' => $source->id]))
            ->assertStatus(401);
    }

    public function testIndexReturns404ForNonExistentSource(): void
    {
        $this->fakeLogin();

        $this->json('GET', route('api.v1.sources.medias.index', ['sourceId' => '01jsvgt3prpypqwex4wj78bznk']))
            ->assertStatus(404);
    }

    public function testIndexReturns404ForInactiveSource(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['status' => Source::STATUS_INACTIVE]);

        $this->json('GET', route('api.v1.sources.medias.index', ['sourceId' => $source->id]))
            ->assertStatus(404);
    }

    public function testIndexReturnsPaginatedMediaOrderedByPublishedAtDesc(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['status' => Source::STATUS_ACTIVE]);
        $older = Media::factory()->create(['source_id' => $source->id, 'published_at' => now()->subDays(5)]);
        $newer = Media::factory()->create(['source_id' => $source->id, 'published_at' => now()]);
        $otherSourceMedia = Media::factory()->create(['published_at' => now()]);

        $response = $this->json('GET', route('api.v1.sources.medias.index', ['sourceId' => $source->id]))
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertEquals([$newer->id, $older->id], $ids->all());
        $this->assertFalse($ids->contains($otherSourceMedia->id));
    }

    public function testIndexRespectsLimitParameter(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['status' => Source::STATUS_ACTIVE]);
        Media::factory()->count(5)->create(['source_id' => $source->id]);

        $response = $this->json('GET', route('api.v1.sources.medias.index', ['sourceId' => $source->id]), ['limit' => 2])
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }
}
