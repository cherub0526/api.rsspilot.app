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

    public function testIndexSucceedsWhenTheMediaIsInTheUsersLibrary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        Caption::factory()->create(['media_id' => $media->id]);

        $user->media()->syncWithoutDetaching([$media->id]);

        $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => $media->id]))
            ->assertStatus(200);
    }

    /**
     * 存取權以 media 為準，訂閱來源本身不再直接授權。
     *
     * 正常流程下訂閱會由 SubscriptionService::syncSourceMediaToUserables() 把
     * 該來源的影片寫進使用者的影片庫，所以這個狀態不常見；但那個方法受 30 天
     * 影片額度限制，額度用完時一列都不會寫——那些影片就不在影片庫裡，也就不
     * 該讀得到字幕。
     */
    public function testIndexReturns404WhenSubscribedButTheMediaIsNotInTheLibrary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        Caption::factory()->create(['media_id' => $media->id]);

        $user->sources()->attach($source->id, ['notify' => true]);

        $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => $media->id]))
            ->assertStatus(404);
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
