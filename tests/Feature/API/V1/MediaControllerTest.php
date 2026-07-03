<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class MediaControllerTest extends TestCase
{
    use RefreshDatabase;

    // ================================================================
    // GET /v1/media (index)
    // ================================================================

    public function testIndexRequiresAuth(): void
    {
        $this->json('GET', route('api.v1.media.index'), ['type' => Media::TYPE_YOUTUBE])
            ->assertStatus(401);
    }

    public function testIndexRequiresType(): void
    {
        $this->fakeLogin();

        $this->json('GET', route('api.v1.media.index'))
            ->assertStatus(422)
            ->assertJsonPath('messages.type.0', __('validators.media.type.required'));
    }

    public function testIndexOnlyReturnsUsersOwnMedia(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $owned = Media::factory()->create(['type' => Media::TYPE_YOUTUBE]);
        $notOwned = Media::factory()->create(['type' => Media::TYPE_YOUTUBE]);
        $user->media()->attach($owned->id);

        $response = $this->json('GET', route('api.v1.media.index'), ['type' => Media::TYPE_YOUTUBE])
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($owned->id));
        $this->assertFalse($ids->contains($notOwned->id));
    }

    public function testIndexFiltersByKeyword(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $matching = Media::factory()->create(['type' => Media::TYPE_YOUTUBE, 'title' => 'How to bake bread']);
        $nonMatching = Media::factory()->create(['type' => Media::TYPE_YOUTUBE, 'title' => 'Learning guitar']);
        $user->media()->attach([$matching->id, $nonMatching->id]);

        $response = $this->json('GET', route('api.v1.media.index'), ['type' => Media::TYPE_YOUTUBE, 'keyword' => 'bread'])
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($matching->id));
        $this->assertFalse($ids->contains($nonMatching->id));
    }

    // ================================================================
    // GET /v1/media/{mediaId} (show)
    // ================================================================

    public function testShowRequiresAuth(): void
    {
        $media = Media::factory()->create();

        $this->json('GET', route('api.v1.media.show', ['mediaId' => $media->id]))->assertStatus(401);
    }

    public function testShowReturns404ForNonExistentMedia(): void
    {
        $this->fakeLogin();

        $this->json('GET', route('api.v1.media.show', ['mediaId' => '01jsvgt3prpypqwex4wj78bznk']))
            ->assertStatus(404);
    }

    public function testShowReturns404WhenUserHasNoAccess(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.show', ['mediaId' => $media->id]))->assertStatus(404);
    }

    public function testShowSucceedsForFreeSourceMedia(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.show', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('id', $media->id);
    }

    public function testShowSucceedsForDirectlyOwnedMedia(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $user->media()->attach($media->id);

        $this->json('GET', route('api.v1.media.show', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('id', $media->id);
    }
}
