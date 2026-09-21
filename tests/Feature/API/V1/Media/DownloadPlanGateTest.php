<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\Caption;
use App\Models\Summary;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 下載摘要／字幕的方案門檻。
 *
 * 判準是 plans.download_enabled——Free 為 false，付費方案為 true。
 * 顯示用的 captions / summaries 端點不受影響，這裡一併驗。
 *
 * @internal
 * @coversNothing
 */
class DownloadPlanGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建立「沒有訂閱時的預設方案」：SubscriptionService 是用「有一筆月費 0 的
     * 價格」認免費方案的，所以 fixture 必須長成那樣。
     */
    private function defaultPlan(bool $downloadEnabled): Plan
    {
        $plan = Plan::factory()->create([
            'title'            => $downloadEnabled ? 'Pro' : 'Free',
            'download_enabled' => $downloadEnabled,
            'sort'             => 0,
        ]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);

        return $plan;
    }

    private function media(): Media
    {
        $source = Source::factory()->create(['free' => true]);

        return Media::factory()->create(['source_id' => $source->id, 'title' => 'A/B 測試怎麼做']);
    }

    private function caption(Media $media): Caption
    {
        return Caption::factory()->create([
            'media_id' => $media->id,
            'locale'   => 'en',
            'segments' => [
                ['start' => 0, 'end' => 2.5, 'text' => 'Hello world'],
                ['start' => 2.5, 'end' => 2.5, 'text' => '零長度片段'],
            ],
        ]);
    }

    private function summary(Media $media): Summary
    {
        return Summary::factory()->create([
            'media_id' => $media->id,
            'user_id'  => null,
            'locale'   => 'zh',
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => [
                'short_summary' => '一句話結論。',
                'long_summary'  => [
                    'content'    => "## 開場\n\n這段講 **重點**。",
                    'key_points' => ['第一點', '第二點'],
                    'keywords'   => ['AB test', '轉換率'],
                ],
            ],
        ]);
    }

    private function captionUri(Media $media, Caption $caption, string $format): string
    {
        return route('api.v1.media.captions.download', [
            'mediaId'   => $media->id,
            'captionId' => $caption->id,
        ]) . '?format=' . $format;
    }

    private function summaryUri(Media $media, string $format): string
    {
        return route('api.v1.media.summaries.download', ['mediaId' => $media->id]) . '?format=' . $format;
    }

    // ================================================================
    // 方案門檻
    // ================================================================

    public function testCaptionDownloadIsBlockedWithoutTheFeature(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(false);

        $media = $this->media();
        $caption = $this->caption($media);

        $this->json('GET', $this->captionUri($media, $caption, 'srt'))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']]);
    }

    public function testSummaryDownloadIsBlockedWithoutTheFeature(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(false);

        $media = $this->media();
        $this->summary($media);

        $this->json('GET', $this->summaryUri($media, 'md'))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']]);
    }

    public function testDownloadIsBlockedWhenNoPlanCanBeResolved(): void
    {
        $this->fakeLogin();

        $media = $this->media();
        $caption = $this->caption($media);

        // 無從判斷權益時的預設是不給。
        $this->json('GET', $this->captionUri($media, $caption, 'srt'))->assertStatus(422);
    }

    public function testDownloadRequiresAuth(): void
    {
        $media = $this->media();
        $caption = $this->caption($media);

        $this->json('GET', $this->captionUri($media, $caption, 'srt'))->assertStatus(401);
    }

    /**
     * 顯示與下載是兩件事：定價頁承諾所有方案都看得到摘要與字幕，
     * 免費方案被擋的只有「產好的檔案」。
     */
    public function testViewingStaysOpenForFreePlans(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(false);

        $media = $this->media();
        $caption = $this->caption($media);
        $this->summary($media);

        $this->json('GET', route('api.v1.media.captions.show', [
            'mediaId'   => $media->id,
            'captionId' => $caption->id,
        ]))->assertStatus(200);

        $this->json('GET', route('api.v1.media.summaries.index', ['mediaId' => $media->id]))
            ->assertStatus(200);
    }

    /**
     * 沒有權限看這支影片時要回 404，不能因為方案沒開通就先回 422——
     * 那等於告訴對方「這個 ID 存在，升級就看得到」。
     */
    public function testInaccessibleMediaIsNotFoundEvenWithoutTheFeature(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(false);

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $caption = $this->caption($media);

        $this->json('GET', $this->captionUri($media, $caption, 'srt'))->assertStatus(404);
    }

    // ================================================================
    // 有權益時的輸出
    // ================================================================

    public function testCaptionDownloadReturnsSrt(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(true);

        $media = $this->media();
        $caption = $this->caption($media);

        $response = $this->get($this->captionUri($media, $caption, 'srt'))->assertStatus(200);

        $body = $response->getContent();

        $this->assertStringContainsString("1\r\n00:00:00,000 --> 00:00:02,500\r\nHello world", $body);
        // end <= start 的片段補成至少一秒，不能被丟掉
        $this->assertStringContainsString('00:00:02,500 --> 00:00:03,500', $body);

        $disposition = $response->getHeaderLine('Content-Disposition');
        $this->assertStringContainsString('attachment;', $disposition);
        // 標題裡的 "/" 會被換成底線，中文走 filename*
        $this->assertStringContainsString("filename*=UTF-8''", $disposition);
        $this->assertStringContainsString('A_B', rawurldecode($disposition));
        $this->assertStringContainsString('.en.srt', rawurldecode($disposition));
    }

    public function testCaptionDownloadReturnsVtt(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(true);

        $media = $this->media();
        $caption = $this->caption($media);

        $body = $this->get($this->captionUri($media, $caption, 'vtt'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringStartsWith("WEBVTT\n\n", $body);
        $this->assertStringContainsString('00:00:00.000 --> 00:00:02.500', $body);
    }

    public function testSummaryDownloadReturnsMarkdown(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(true);

        $media = $this->media();
        $this->summary($media);

        $body = $this->get($this->summaryUri($media, 'md'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringStartsWith('# A/B 測試怎麼做', $body);
        // 內文自己的 ## 要降一級，才不會跟段落標題同級
        $this->assertStringContainsString("\n### 開場\n", $body);
        $this->assertStringContainsString('- 第一點', $body);
        $this->assertStringContainsString('AB test, 轉換率', $body);
    }

    public function testSummaryDownloadReturnsPlainText(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(true);

        $media = $this->media();
        $this->summary($media);

        $body = $this->get($this->summaryUri($media, 'txt'))
            ->assertStatus(200)
            ->getContent();

        // 純文字不留任何 Markdown 語法
        $this->assertStringNotContainsString('##', $body);
        $this->assertStringNotContainsString('**', $body);
        $this->assertStringContainsString('這段講 重點。', $body);
    }

    public function testUnsupportedFormatIsRejected(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(true);

        $media = $this->media();
        $caption = $this->caption($media);

        $this->json('GET', $this->captionUri($media, $caption, 'docx'))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['format']]);
    }

    public function testSummaryDownloadFailsWhenThereIsNoCompletedSummary(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(true);

        $media = $this->media();

        $this->json('GET', $this->summaryUri($media, 'md'))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['summary']]);
    }
}
