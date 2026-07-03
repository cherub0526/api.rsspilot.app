<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\Caption;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class CaptionsControllerTest extends TestCase
{
    use RefreshDatabase;

    // ================================================================
    // GET /v1/media/{mediaId}/captions (index)
    // ================================================================

    public function testIndexRequiresAuth(): void
    {
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => $media->id]))
            ->assertStatus(401);
    }

    public function testIndexReturns404ForNonExistentMedia(): void
    {
        $this->fakeLogin();

        $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => '01jsvgt3prpypqwex4wj78bznk']))
            ->assertStatus(404);
    }

    public function testIndexReturns404WhenSourceNotAccessible(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => $media->id]))
            ->assertStatus(404);
    }

    public function testIndexSucceedsForFreeSource(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $primary = Caption::factory()->create(['media_id' => $media->id, 'locale' => 'en', 'primary' => true]);
        $secondary = Caption::factory()->create(['media_id' => $media->id, 'locale' => 'zh', 'primary' => false]);

        $response = $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => $media->id]))
            ->assertStatus(200);

        // primary caption ordered first
        $this->assertEquals($primary->id, $response->json('data.0.id'));
        $this->assertEquals($secondary->id, $response->json('data.1.id'));
    }

    public function testIndexSucceedsWhenUserSubscribedToSource(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        Caption::factory()->create(['media_id' => $media->id]);

        $user->sources()->attach($source->id, ['notify' => true]);

        $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => $media->id]))
            ->assertStatus(200);
    }

    // ================================================================
    // GET /v1/media/{mediaId}/captions/{captionId} (show)
    // ================================================================

    public function testShowRequiresAuth(): void
    {
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $caption = Caption::factory()->create(['media_id' => $media->id]);

        $this->json('GET', route('api.v1.media.captions.show', ['mediaId' => $media->id, 'captionId' => $caption->id]))
            ->assertStatus(401);
    }

    public function testShowReturns404ForNonExistentCaption(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.captions.show', ['mediaId' => $media->id, 'captionId' => '01jsvgt3prpypqwex4wj78bznk']))
            ->assertStatus(404);
    }

    public function testShowSucceedsAndReturnsSegments(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $caption = Caption::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'en',
            'segments' => [['start' => 0.5, 'end' => 1.2, 'text' => ' Hello ']],
        ]);

        $this->json('GET', route('api.v1.media.captions.show', ['mediaId' => $media->id, 'captionId' => $caption->id]))
            ->assertStatus(200)
            ->assertJsonPath('id', $caption->id)
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('segments.0.text', 'Hello');
    }
}
