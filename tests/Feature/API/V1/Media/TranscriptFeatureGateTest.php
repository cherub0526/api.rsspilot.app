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
 * 全站的逐字稿下載開關（config features.transcript_download / FEATURE_TRANSCRIPT）。
 *
 * 關閉時不能把原話匯出成檔案：字幕檔下載回 404，跟「沒有這個資源」同一個形狀。
 * 方案再高也一樣——這是全站開關，不是權益。App 內檢視逐字稿不受影響。
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

    public function testDownloadIsNotFoundWhenTheFeatureIsOffEvenOnAPlanThatAllowsDownloads(): void
    {
        config(['features.transcript_download' => false]);
        $this->fakeLogin();

        $this->json('GET', $this->downloadUri())
            ->assertStatus(404)
            ->assertExactJson(['messages' => 'Resource not found.']);
    }

    public function testAnonymousDownloadsStillGet401WhenTheFeatureIsOff(): void
    {
        config(['features.transcript_download' => false]);

        $this->json('GET', $this->downloadUri())->assertStatus(401);
    }

    /** 開關只管匯出成檔案；在 App 內看逐字稿照舊。 */
    public function testViewingStaysOpenWhenTheFeatureIsOff(): void
    {
        config(['features.transcript_download' => false]);
        $this->fakeLogin();

        $this->json('GET', route('api.v1.media.captions.index', ['mediaId' => $this->media->id]))
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $this->caption->id);

        $this->json('GET', $this->showUri())
            ->assertStatus(200)
            ->assertJsonPath('segments.0.text', 'Hello world');
    }

    public function testDownloadWorksWhenTheFeatureIsOn(): void
    {
        config(['features.transcript_download' => true]);
        $this->fakeLogin();

        $this->get($this->downloadUri())->assertStatus(200);
    }

    public function testTheFeatureIsOffByDefault(): void
    {
        $this->fakeLogin();

        $this->json('GET', $this->downloadUri())->assertStatus(404);
    }
}
