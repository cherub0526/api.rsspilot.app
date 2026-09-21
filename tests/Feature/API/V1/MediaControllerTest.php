<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\Summary;
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

    /**
     * short_summary 與 /summaries、chat 取同一份摘要，見 Media::summaryFor()。
     */
    public function testShowUsesTheUsersOwnSummaryForShortSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $user->setting()->create(['data' => ['locale' => 'zh-TW']]);

        $media = Media::factory()->create();
        $user->media()->attach($media->id);

        Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'zh-TW',
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['short_summary' => 'shared zh'],
        ]);
        Summary::factory()->create([
            'media_id' => $media->id,
            'user_id'  => $user->id,
            'locale'   => 'zh-TW',
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['short_summary' => 'mine zh'],
        ]);

        $this->json('GET', route('api.v1.media.show', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('short_summary', 'mine zh');
    }

    /**
     * 重跑摘要會先建一筆 status=created、text 還是 null 的資料列。原本的三元只
     * 判斷有沒有資料列，撈到它就是對 null 取索引 —— 在這個專案是 500。
     */
    public function testShowReturnsAnEmptyShortSummaryWhileASummaryIsStillPending(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $user->media()->attach($media->id);

        Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'en',
            'status'   => Summary::STATUS_CREATED,
            'text'     => null,
        ]);

        $this->json('GET', route('api.v1.media.show', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('short_summary', '');
    }

    /** 前端靠 status 區分「處理中」與「已完成」，缺了它只能猜。 */
    public function testShowReturnsProcessingStatus(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $media = Media::factory()->create(['status' => Media::STATUS_SUMMARIZING]);
        $user->media()->attach($media->id);

        $this->json('GET', route('api.v1.media.show', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('status', Media::STATUS_SUMMARIZING);
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
