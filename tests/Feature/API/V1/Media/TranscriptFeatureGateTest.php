<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\Caption;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 全站的逐字稿開關（config features.transcript / FEATURE_TRANSCRIPT）。
 *
 * 關閉時使用者拿不到原話：看逐字稿與下載字幕檔都回 404，跟「沒有這個資源」
 * 同一個形狀。方案再高也一樣——這是全站開關，不是權益。
 *
 * @internal
 * @coversNothing
 */
class TranscriptFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    private Caption $caption;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'            => 'Pro',
            'download_enabled' => true,
            'sort'             => 0,
        ]));
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);

        $source = Source::factory()->create(['free' => true]);
        $this->media = Media::factory()->create(['source_id' => $source->id]);
        $this->caption = Caption::factory()->create([
            'media_id' => $this->media->id,
            'locale'   => 'en',
            'segments' => [['start' => 0, 'end' => 2.5, 'text' => 'Hello world']],
        ]);
    }

    private function showUri(): string
    {
        return route('api.v1.media.captions.show', ['mediaId' => $this->media->id, 'captionId' => $this->caption->id]);
    }

    private function downloadUri(): string
    {
        return route('api.v1.media.captions.download', [
            'mediaId'   => $this->media->id,
            'captionId' => $this->caption->id,
        ]) . '?format=srt';
    }

    public function testShowIsNotFoundWhenTheFeatureIsOff(): void
    {
        config(['features.transcript' => false]);
        $this->fakeLogin();

        $this->json('GET', $this->showUri())
            ->assertStatus(404)
            ->assertExactJson(['messages' => 'Resource not found.']);
    }

    public function testDownloadIsNotFoundWhenTheFeatureIsOffEvenOnAPlanThatAllowsDownloads(): void
    {
        config(['features.transcript' => false]);
        $this->fakeLogin();

        $this->json('GET', $this->downloadUri())
            ->assertStatus(404)
            ->assertExactJson(['messages' => 'Resource not found.']);
    }

    public function testAnonymousRequestsStillGet401WhenTheFeatureIsOff(): void
    {
        config(['features.transcript' => false]);

        $this->json('GET', $this->showUri())->assertStatus(401);
    }

    /** 列表只有 id 與 locale，沒有原話；前端靠它判斷有沒有字幕。 */
    public function testIndexStaysOpenWhenTheFeatureIsOff(): void
    {
        config(['features.transcript' => false]);
        $this->fakeLogin();

        $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => $this->media->id]))
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $this->caption->id)
            ->assertJsonPath('data.0.segments', []);
    }

    public function testShowAndDownloadWorkWhenTheFeatureIsOn(): void
    {
        config(['features.transcript' => true]);
        $this->fakeLogin();

        $this->json('GET', $this->showUri())
            ->assertStatus(200)
            ->assertJsonPath('segments.0.text', 'Hello world');

        $this->get($this->downloadUri())->assertStatus(200);
    }

    public function testTheFeatureIsOffByDefault(): void
    {
        $this->fakeLogin();

        $this->json('GET', $this->showUri())->assertStatus(404);
    }
}
