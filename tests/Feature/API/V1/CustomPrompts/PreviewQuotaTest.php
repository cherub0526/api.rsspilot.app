<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\CustomPrompts;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Caption;
use App\Models\ChatUsage;
use Mockery\MockInterface;
use App\Services\SummaryPreviewService;
use App\Exceptions\InvalidRequestException;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * POST /v1/custom-prompts/preview 的每日額度。
 *
 * 試跑不落地任何東西，所以除了方案閘門之外沒有任何天然的節流——按一下就是一次
 * 推論。它與 AI 對話共用同一份額度（plans.chat_limit / chat_usages）：兩者都是
 * 使用者主動觸發的一次推論，成本同源，不該各自有一個可以分開刷爆的桶。
 *
 * @internal
 * @coversNothing
 */
class PreviewQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function uri(): string
    {
        return route('api.v1.custom-prompts.preview.store');
    }

    /**
     * 沒有訂閱的使用者吃的是「月費 0 元」的方案，額度測試只要建這個方案即可。
     *
     * 包 withoutEvents：Plan / Price 的 observer 會直接打 Stripe API。
     */
    private function createPlan(int $chatLimit): Plan
    {
        return Plan::withoutEvents(function () use ($chatLimit) {
            $plan = Plan::factory()->create([
                'title'                  => 'Pro',
                'custom_summary_enabled' => true,
                'chat_limit'             => $chatLimit,
                'sort'                   => 0,
            ]);

            Price::create(['plan_id' => $plan->id, 'unit' => Price::UNIT_MONTHLY, 'price' => 0]);

            return $plan;
        });
    }

    private function mediaWithCaption(User $user): Media
    {
        $media = Media::factory()->create();
        $user->media()->attach($media->getKey());

        Caption::factory()->create([
            'media_id' => $media->getKey(),
            'text'     => '這部影片在講 AI 工作流程。',
            'primary'  => true,
        ]);

        return $media;
    }

    /**
     * 推論一律 mock——測試不得對外發請求。
     */
    private function fakePreview(): void
    {
        $this->mock(SummaryPreviewService::class, function (MockInterface $mock) {
            $mock->shouldReceive('preview')->andReturn([
                'short_summary' => '一句話總結。',
                'long_summary'  => ['content' => '完整的長摘要。', 'key_points' => [], 'keywords' => []],
            ]);
        });
    }

    private function preview(Media $media)
    {
        return $this->json('POST', $this->uri(), [
            'media_id' => $media->getKey(),
            'content'  => '請整理重點。',
        ]);
    }

    private function usageOf(User $user): int
    {
        return (int) (ChatUsage::query()->where('user_id', $user->id)->value('count') ?? 0);
    }

    /**
     * 一次試跑扣 1 點，與一則純文字提問等價。
     */
    public function testAPreviewConsumesOneUnitOfTheDailyChatQuota(): void
    {
        $this->createPlan(3);
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);
        $this->fakePreview();

        $response = $this->preview($media);

        $response->assertStatus(200);
        $this->assertSame(1, $this->usageOf($user));
        $this->assertSame('3', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('2', $response->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertNotSame('', $response->getHeaderLine('X-RateLimit-Reset'));
    }

    /**
     * 額度用完就擋，且被擋下來的請求不留下用量——否則重試一次數字就多長一格。
     */
    public function testAPreviewIsBlockedOnceTheDailyQuotaIsExhausted(): void
    {
        $this->createPlan(1);
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);
        $this->fakePreview();

        $this->preview($media)->assertStatus(200);

        $response = $this->preview($media);

        $response->assertStatus(429);
        $this->assertSame(1, $this->usageOf($user));
        $this->assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    /**
     * 額度是共用的一份：對話用掉的次數，試跑這邊同樣看得到。
     */
    public function testChatUsageCountsAgainstTheSamePool(): void
    {
        $this->createPlan(2);
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);
        $this->fakePreview();

        // 先讓對話吃掉 1 點，試跑剩 1 點。
        ChatUsage::query()->create([
            'user_id'    => $user->id,
            'quota_date' => now(config('ai.chat.quota_timezone') ?: config('app.timezone'))->toDateString(),
            'count'      => 1,
        ]);

        $this->assertSame('0', $this->preview($media)->getHeaderLine('X-RateLimit-Remaining'));
        $this->preview($media)->assertStatus(429);
    }

    /**
     * 推論失敗要退還：試跑沒有「已經吐了一半」的中間狀態，使用者什麼都沒拿到。
     */
    public function testAFailedPreviewReleasesTheQuota(): void
    {
        $this->createPlan(3);
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);

        $this->mock(SummaryPreviewService::class, function (MockInterface $mock) {
            $mock->shouldReceive('preview')->andThrow(new InvalidRequestException(['content' => ['failed']]));
        });

        $this->preview($media)->assertStatus(422);
        $this->assertSame(0, $this->usageOf($user));
    }

    /**
     * 請求本身就不合法（別人的影片、字幕還沒好）時一次推論都沒發生，不該扣點。
     */
    public function testAnInvalidRequestDoesNotConsumeQuota(): void
    {
        $this->createPlan(3);
        $user = $this->fakeLogin();

        $media = Media::factory()->create();
        $user->media()->attach($media->getKey());

        $this->preview($media)->assertStatus(422);
        $this->assertSame(0, $this->usageOf($user));
    }

    /**
     * chat_limit = 0 → 不限制，且不帶 X-RateLimit-*（送 Limit: 0 會被讀成一次都不能用）。
     */
    public function testAnUnlimitedPlanCarriesNoRateLimitHeaders(): void
    {
        $this->createPlan(0);
        $user = $this->fakeLogin();
        $media = $this->mediaWithCaption($user);
        $this->fakePreview();

        $response = $this->preview($media);

        $response->assertStatus(200);
        $this->assertSame('', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('', $response->getHeaderLine('X-RateLimit-Remaining'));
    }
}
