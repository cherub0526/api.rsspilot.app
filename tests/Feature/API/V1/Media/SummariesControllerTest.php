<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Summary;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class SummariesControllerTest extends TestCase
{
    use RefreshDatabase;

    // ================================================================
    // GET /v1/media/{mediaId}/summaries (index)
    // ================================================================

    public function testIndexRequiresAuth(): void
    {
        $media = Media::factory()->create();

        $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(401);
    }

    public function testIndexReturns422WhenMediaNotOwnedByUser(): void
    {
        $this->fakeLogin();

        $media = Media::factory()->create();

        $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(422)
            ->assertJsonPath('messages.media.0', __('validators.controllers.media.not_found'));
    }

    public function testIndexReturnsEmptyArrayWhenNoSummaryExists(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $user->media()->attach($media->id);

        $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertExactJson([]);
    }

    public function testIndexReturnsSummaryWhenPresent(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $user->media()->attach($media->id);
        $summary = Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'en',
            'text'     => 'A short summary.',
        ]);

        $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('text', 'A short summary.');
    }

    // ================================================================
    // GET /v1/media/{mediaId}/summaries/{summaryId} (show)
    // ================================================================

    public function testShowRequiresAuth(): void
    {
        $media = Media::factory()->create();
        $summary = Summary::factory()->create(['media_id' => $media->id, 'locale' => 'en']);

        $this->json('GET', route('api.v1.media.summaries.show', ['mediaId' => $media->id, 'summaryId' => $summary->id]))
            ->assertStatus(401);
    }

    public function testShowReturns422WhenMediaNotOwnedByUser(): void
    {
        $this->fakeLogin();

        $media = Media::factory()->create();
        $summary = Summary::factory()->create(['media_id' => $media->id, 'locale' => 'en']);

        $this->json('GET', route('api.v1.media.summaries.show', ['mediaId' => $media->id, 'summaryId' => $summary->id]))
            ->assertStatus(422)
            ->assertJsonPath('messages.media.0', __('validators.controllers.media.not_found'));
    }

    public function testShowReturns404ForNonExistentSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $user->media()->attach($media->id);

        $this->json('GET', route('api.v1.media.summaries.show', ['mediaId' => $media->id, 'summaryId' => '01jsvgt3prpypqwex4wj78bznk']))
            ->assertStatus(404);
    }

    public function testShowSucceeds(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $user->media()->attach($media->id);
        $summary = Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'zh-TW',
            'text'     => 'A detailed summary.',
        ]);

        $this->json('GET', route('api.v1.media.summaries.show', ['mediaId' => $media->id, 'summaryId' => $summary->id]))
            ->assertStatus(200)
            ->assertJsonPath('locale', 'zh-TW')
            ->assertJsonPath('text', 'A detailed summary.');
    }

    public function testShowReturns404WhenSummaryBelongsToDifferentMedia(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $otherMedia = Media::factory()->create();
        $user->media()->attach([$media->id, $otherMedia->id]);
        $summary = Summary::factory()->create(['media_id' => $otherMedia->id, 'locale' => 'en']);

        $this->json('GET', route('api.v1.media.summaries.show', ['mediaId' => $media->id, 'summaryId' => $summary->id]))
            ->assertStatus(404);
    }
}
