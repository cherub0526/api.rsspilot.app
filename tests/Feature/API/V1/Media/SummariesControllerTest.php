<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

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
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => 'A short summary.',
        ]);

        $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('text', 'A short summary.');
    }

    /**
     * 重跑摘要會先建一筆 status=created、text 還是 null 的資料列。這支端點只回
     * 已完成的，所以重跑期間看到的仍是舊的那份，而不是被清空。
     */
    public function testIndexKeepsTheCompletedSummaryWhileANewOneIsPending(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $this->summary($media, ['locale' => 'en', 'text' => 'done']);
        Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'en',
            'status'   => Summary::STATUS_CREATED,
            'text'     => null,
        ]);

        $this->indexJson($media)->assertJsonPath('text', 'done');
    }

    /**
     * 從沒產生過完成的摘要時回空陣列——前端在這一刻看不到 status=created，
     * 這是 index 只回已完成摘要換來的代價。
     */
    public function testIndexReturnsEmptyArrayWhileTheFirstSummaryIsStillPending(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'en',
            'status'   => Summary::STATUS_CREATED,
            'text'     => null,
        ]);

        $this->indexJson($media)->assertExactJson([]);
    }

    // ================================================================
    // index 的多筆摘要挑選順序
    // ================================================================

    public function testIndexPrefersTheUsersOwnSummaryInTheirLocale(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $this->givenUiLocale($user, 'zh-TW');

        $this->summary($media, ['user_id' => $user->id, 'locale' => 'zh-TW', 'text' => 'mine zh']);
        $this->summary($media, ['user_id' => $user->id, 'locale' => 'en', 'text' => 'mine en']);
        $this->summary($media, ['locale' => 'zh-TW', 'text' => 'shared zh']);
        $this->summary($media, ['locale' => 'en', 'text' => 'shared en']);

        $this->indexJson($media)->assertJsonPath('text', 'mine zh');
    }

    public function testIndexFallsBackToTheSharedSummaryInTheUsersLocale(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $this->givenUiLocale($user, 'zh-TW');

        $this->summary($media, ['user_id' => $user->id, 'locale' => 'en', 'text' => 'mine en']);
        $this->summary($media, ['locale' => 'en', 'text' => 'shared en']);
        $this->summary($media, ['locale' => 'zh-TW', 'text' => 'shared zh']);

        $this->indexJson($media)->assertJsonPath('text', 'shared zh');
    }

    public function testIndexFallsBackToTheFirstSharedSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $this->givenUiLocale($user, 'zh-TW');

        $this->summary($media, ['locale' => 'en', 'text' => 'shared en']);
        $this->summary($media, ['locale' => 'ja', 'text' => 'shared ja']);

        $this->indexJson($media)->assertJsonPath('text', 'shared en');
    }

    public function testIndexNeverReturnsAnotherUsersSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $this->givenUiLocale($user, 'zh-TW');

        $other = User::factory()->create();
        $this->summary($media, ['user_id' => $other->id, 'locale' => 'zh-TW', 'text' => 'theirs zh']);
        $this->summary($media, ['locale' => 'en', 'text' => 'shared en']);

        $this->indexJson($media)->assertJsonPath('text', 'shared en');
    }

    public function testIndexWithoutALocaleSettingStillPrefersTheUsersOwnSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $this->summary($media, ['locale' => 'en', 'text' => 'shared en']);
        $this->summary($media, ['user_id' => $user->id, 'locale' => 'ja', 'text' => 'mine ja']);

        $this->indexJson($media)->assertJsonPath('text', 'mine ja');
    }

    /**
     * settings 存的是 `zh-TW`，早期的摘要沿用字幕的 `zh_tw`——遷移把既有資料
     * 洗成前者，寫入端也已正規化，所以查詢端直接字面比對即可命中。
     */
    public function testIndexMatchesTheLocaleStoredInIsoForm(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $this->givenUiLocale($user, 'zh-TW');

        $this->summary($media, ['locale' => 'en', 'text' => 'shared en']);
        $this->summary($media, ['locale' => Summary::LOCALE_ZH_TW, 'text' => 'shared zh']);

        $this->indexJson($media)->assertJsonPath('text', 'shared zh');
    }

    public function testShowNeverExposesAnotherUsersSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $other = User::factory()->create();
        $theirs = $this->summary($media, ['user_id' => $other->id, 'locale' => 'en', 'text' => 'theirs']);

        $this->json('GET', route('api.v1.media.summaries.show', [
            'mediaId'   => $media->id,
            'summaryId' => $theirs->id,
        ]))->assertStatus(404);
    }

    public function testShowReturnsTheUsersOwnSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $mine = $this->summary($media, ['user_id' => $user->id, 'locale' => 'en', 'text' => 'mine']);

        $this->json('GET', route('api.v1.media.summaries.show', [
            'mediaId'   => $media->id,
            'summaryId' => $mine->id,
        ]))->assertStatus(200)->assertJsonPath('text', 'mine');
    }

    private function ownedMedia(User $user): Media
    {
        $media = Media::factory()->create();
        $user->media()->attach($media->id);

        return $media;
    }

    private function givenUiLocale(User $user, string $locale): void
    {
        $user->setting()->create(['data' => ['locale' => $locale]]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function summary(Media $media, array $attributes): Summary
    {
        return Summary::factory()->create($attributes + [
            'media_id' => $media->id,
            'status'   => Summary::STATUS_COMPLETED,
        ]);
    }

    private function indexJson(Media $media)
    {
        return $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(200);
    }

    /**
     * 免費來源的影片不需要先加進影片庫就能讀摘要。
     *
     * 這一段原本用 $request->user()->media()->find()，只看 userables 綁定、
     * 不看來源是否免費，同一支影片的 captions 拿得到、摘要卻回 422。改為與
     * 其他端點共用 Media::isAccessibleBy 之後兩邊一致。
     */
    public function testIndexSucceedsForFreeSourceWithoutOwningTheMedia(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        Summary::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'en',
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => 'A short summary.',
        ]);

        $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonPath('locale', 'en');
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
