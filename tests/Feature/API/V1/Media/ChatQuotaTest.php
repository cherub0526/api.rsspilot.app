<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use DateTimeInterface;
use App\Models\ChatUsage;
use Tests\Support\FakeChatStreamer;
use Hypervel\Support\Facades\Storage;
use Tests\Support\FailingChatStreamer;
use App\Utils\AI\ChatStreamerInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 每日 AI 提問額度（plans.chat_limit）。
 *
 * 純文字提問扣 1 點，帶圖提問扣 2 點——vision 推論明顯貴於純文字，見
 * ChatController::QUOTA_COST_WITH_IMAGES。
 *
 * @internal
 * @coversNothing
 */
class ChatQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 沒有訂閱的使用者吃的是「月費 0 元」的方案，所以額度測試只要建這個方案即可。
     */
    private function createFreePlan(int $chatLimit): Plan
    {
        // 包 withoutEvents：Plan 與 Price 的 observer 會直接 new StripeClient()
        // 打 Stripe API。不擋的話這個 helper 每呼叫一次就是數次網路往返，實測
        // 整個 test case 從 0.7 秒變成 10.7 秒，而且金鑰若有效就會真的建出商品。
        return Plan::withoutEvents(function () use ($chatLimit) {
            $plan = Plan::factory()->create([
                'title'      => 'Free',
                'chat_limit' => $chatLimit,
                'status'     => Plan::STATUS_ACTIVE,
            ]);

            Price::create([
                'plan_id' => $plan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 0,
            ]);

            return $plan;
        });
    }

    private function createAccessibleMedia(): Media
    {
        $source = Source::factory()->create(['free' => true]);

        return Media::factory()->create(['source_id' => $source->id]);
    }

    private function ask(Media $media, string $question = '這部影片在講什麼？')
    {
        return $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => $question]],
        ]);
    }

    private function fakeStreamer(string ...$tokens): FakeChatStreamer
    {
        $streamer = new FakeChatStreamer($tokens === [] ? ['Hello'] : $tokens);
        $this->app->instance(ChatStreamerInterface::class, $streamer);

        return $streamer;
    }

    /** 開通截圖的方案；帶圖提問會先過 plans.screenshot_enabled 這道閘門。 */
    private function createPlanWithScreenshots(int $chatLimit): Plan
    {
        return Plan::withoutEvents(function () use ($chatLimit) {
            $plan = Plan::factory()->create([
                'title'              => 'Advance',
                'chat_limit'         => $chatLimit,
                'screenshot_enabled' => true,
                'status'             => Plan::STATUS_ACTIVE,
            ]);

            Price::create(['plan_id' => $plan->id, 'unit' => Price::UNIT_MONTHLY, 'price' => 0]);

            return $plan;
        });
    }

    private function fakeS3(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiration): string => 'https://signed.test/' . $path
        );
    }

    /** 把一張截圖放進 fake S3，回傳它的 checksum。 */
    private function captureFrame(Media $media, int $second = 125): string
    {
        $checksum = hash('sha256', "frame-{$second}");

        Storage::disk('s3')->put(
            sprintf('media/%s/thumbnails/%06d.%s.jpg', $media->id, $second, $checksum),
            'bytes'
        );

        return $checksum;
    }

    private function askWithImage(Media $media, int $second = 125)
    {
        return $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => '這一格在講什麼？',
                    'images'  => [['second' => $second, 'checksum' => $this->captureFrame($media, $second)]],
                ],
            ],
        ]);
    }

    private function failingStreamer(string ...$tokens): FailingChatStreamer
    {
        $streamer = new FailingChatStreamer($tokens);
        $this->app->instance(ChatStreamerInterface::class, $streamer);

        return $streamer;
    }

    // ================================================================

    /**
     * 未達上限 → 200，且成功回應也要帶 X-RateLimit-*。
     */
    public function testStoreReturnsRateLimitHeadersWhenUnderLimit(): void
    {
        $this->fakeLogin();
        $this->createFreePlan(3);
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        $response = $this->ask($media)->assertStatus(200);

        $this->assertSame('3', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('2', $response->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertNotSame('', $response->getHeaderLine('X-RateLimit-Reset'));
    }

    /**
     * 每問一次就少一次，額度日的計數落在 chat_usages。
     */
    public function testStoreDecrementsRemainingOnEachQuestion(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(3);
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        $this->assertSame('2', $this->ask($media)->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame('1', $this->ask($media)->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame('0', $this->ask($media)->getHeaderLine('X-RateLimit-Remaining'));

        $this->assertDatabaseHas('chat_usages', [
            'user_id'    => $user->id,
            'quota_date' => Carbon::now(config('ai.chat.quota_timezone'))->toDateString(),
            'count'      => 3,
        ]);
    }

    /**
     * 用滿之後 → 429，而且被擋下來的請求不留下 session / 訊息，也不再扣點。
     */
    public function testStoreReturns429WhenDailyLimitReached(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(2);
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        $this->ask($media)->assertStatus(200);
        $this->ask($media)->assertStatus(200);

        $response = $this->ask($media, '第三個問題');

        $response->assertStatus(429)
            ->assertJsonStructure(['messages' => ['chat']]);

        $this->assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));

        // 被擋下來的請求在建立 session 之前就中止
        $this->assertDatabaseCount('chat_sessions', 2);
        $this->assertDatabaseMissing('chat_messages', ['content' => '第三個問題']);

        // 而且不會因為被擋而多算一次
        $this->assertDatabaseHas('chat_usages', ['user_id' => $user->id, 'count' => 2]);
    }

    /**
     * chat_limit = 0 → 不限制，且不帶 X-RateLimit-*（送 Limit: 0 會被讀成一次都不能問）。
     */
    public function testStoreIsUnlimitedWhenChatLimitIsZero(): void
    {
        $this->fakeLogin();
        $this->createFreePlan(0);
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        foreach (range(1, 5) as $ignored) {
            $response = $this->ask($media)->assertStatus(200);
        }

        $this->assertSame('', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    /**
     * 完全沒有訂閱方案可解析時（資料庫沒有方案）也不該擋人。
     */
    public function testStoreIsUnlimitedWhenNoPlanExists(): void
    {
        $this->fakeLogin();
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        $this->ask($media)->assertStatus(200);
        $this->assertDatabaseCount('chat_usages', 1);
    }

    /**
     * 串流一個 token 都沒吐出來就失敗 → 退還額度。
     */
    public function testFailedStreamWithNoOutputReleasesQuota(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(3);
        $media = $this->createAccessibleMedia();
        $this->failingStreamer();

        $this->ask($media);

        $this->assertDatabaseHas('chat_usages', ['user_id' => $user->id, 'count' => 0]);
    }

    /**
     * 已經串出部分內容才失敗 → 算用掉，不退還。
     *
     * 使用者實際看到了回應、上游 token 也已經花掉。
     */
    public function testFailedStreamWithPartialOutputKeepsQuotaConsumed(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(3);
        $media = $this->createAccessibleMedia();
        $this->failingStreamer('這部');

        $this->ask($media);

        $this->assertDatabaseHas('chat_usages', ['user_id' => $user->id, 'count' => 1]);
    }

    /**
     * 跨日 → 額度重置。
     */
    public function testQuotaResetsOnTheNextQuotaDay(): void
    {
        $this->fakeLogin();
        $this->createFreePlan(1);
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        Carbon::setTestNow(Carbon::parse('2026-08-14 23:30:00', config('ai.chat.quota_timezone')));

        $this->ask($media)->assertStatus(200);
        $this->ask($media)->assertStatus(429);

        Carbon::setTestNow(Carbon::parse('2026-08-15 00:30:00', config('ai.chat.quota_timezone')));

        $this->ask($media)->assertStatus(200);
    }

    /**
     * 當日升級方案 → 立刻能用新方案的剩餘額度（用量不依方案分桶）。
     */
    public function testUpgradingPlanOnTheSameDayLiftsTheLimitImmediately(): void
    {
        $this->fakeLogin();
        $plan = $this->createFreePlan(1);
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        $this->ask($media)->assertStatus(200);
        $this->ask($media)->assertStatus(429);

        $plan->update(['chat_limit' => 5]);

        $response = $this->ask($media)->assertStatus(200);
        $this->assertSame('3', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    /**
     * 降級到比當日已用還低的方案 → 剩餘收斂到 0，不會出現負數。
     */
    public function testDowngradingBelowTodaysUsageClampsRemainingToZero(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $plan = $this->createFreePlan(5);
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        $this->ask($media)->assertStatus(200);
        $this->ask($media)->assertStatus(200);
        $this->ask($media)->assertStatus(200);

        $plan->update(['chat_limit' => 2]);

        $response = $this->ask($media)->assertStatus(429);

        $this->assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));

        $this->assertSame(3, ChatUsage::query()->where('user_id', $user->id)->value('count'));
    }

    /**
     * 刪掉 session 不會洗掉當日用量（用量記在 chat_usages，不是數訊息）。
     */
    public function testDeletingSessionsDoesNotRestoreQuota(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(1);
        $media = $this->createAccessibleMedia();
        $this->fakeStreamer();

        $sessionId = $this->ask($media)->json('session_id');

        $this->json('DELETE', route('api.v1.media.chat.sessions.destroy', [
            'mediaId'   => $media->id,
            'sessionId' => $sessionId,
        ]))->assertStatus(200);

        $this->ask($media)->assertStatus(429);
    }

    // ================================================================
    // 帶圖提問的權重
    // ================================================================

    public function testImageQuestionConsumesTwoQuotaUnits(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();
        $this->createPlanWithScreenshots(10);

        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->createAccessibleMedia();

        $this->askWithImage($media)
            ->assertStatus(200)
            ->assertHeader('X-RateLimit-Remaining', '8');

        $this->assertSame(2, ChatUsage::query()->where('user_id', $user->id)->value('count'));
    }

    public function testTextOnlyQuestionStillConsumesOne(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();
        $this->createPlanWithScreenshots(10);

        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->createAccessibleMedia();

        $this->ask($media)
            ->assertStatus(200)
            ->assertHeader('X-RateLimit-Remaining', '9');

        $this->assertSame(1, ChatUsage::query()->where('user_id', $user->id)->value('count'));
    }

    /**
     * 扣點看的是「這一則附了圖沒有」，不是整包 payload 裡有沒有圖。
     *
     * 歷史中的截圖仍然會被送進推論（見〈帶圖提問有兩層上限〉），但那是前一輪已經
     * 扣過 2 點的東西。歷史改由 server 重建之後使用者無從把它拿掉，若沿用「payload
     * 有圖就扣 2 點」，附過一次圖之後的每一則純文字追問都會變成 2 點，而且前端在
     * 沒附圖時根本不會標示「這則扣 2 次」。
     */
    public function testTextOnlyFollowUpAfterAnImageQuestionStillConsumesOne(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();
        $this->createPlanWithScreenshots(10);

        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->createAccessibleMedia();

        $sessionId = $this->askWithImage($media)
            ->assertStatus(200)
            ->assertHeader('X-RateLimit-Remaining', '8')
            ->json('session_id');

        // 同一段對話的純文字追問：歷史裡那張圖照樣送進推論，但不再扣一次點。
        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'session_id' => $sessionId,
            'messages'   => [['role' => 'user', 'content' => '所以結論是什麼？']],
        ])
            ->assertStatus(200)
            ->assertHeader('X-RateLimit-Remaining', '7');

        $this->assertSame(3, ChatUsage::query()->where('user_id', $user->id)->value('count'));
    }

    /**
     * 剩 1 點時帶圖提問要整個擋下來，不做部分扣點——否則會變成用 1 點買到 2 點的東西。
     */
    public function testImageQuestionIsRejectedWhenOnlyOneUnitRemains(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();
        $this->createPlanWithScreenshots(3);

        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->createAccessibleMedia();

        $this->ask($media)->assertStatus(200);
        $this->ask($media)->assertStatus(200);
        $this->assertSame(2, ChatUsage::query()->where('user_id', $user->id)->value('count'));

        $this->askWithImage($media)->assertStatus(429);

        // 被擋下來的請求不留用量
        $this->assertSame(2, ChatUsage::query()->where('user_id', $user->id)->value('count'));

        // 同樣的剩餘額度仍夠一次純文字提問
        $this->ask($media)->assertStatus(200);
        $this->assertSame(3, ChatUsage::query()->where('user_id', $user->id)->value('count'));
    }

    /** 一個 token 都沒產出時要把扣掉的 2 點整數退還，不是退 1 點。 */
    public function testFailedImageQuestionReleasesBothUnits(): void
    {
        $this->fakeS3();
        $this->failingStreamer();
        $this->createPlanWithScreenshots(10);

        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->createAccessibleMedia();

        $this->askWithImage($media);

        $this->assertSame(0, ChatUsage::query()->where('user_id', $user->id)->value('count'));
    }

    /** 不限額度的方案不因為帶圖而有任何差別。 */
    public function testImageQuestionIsUnlimitedWhenChatLimitIsZero(): void
    {
        $this->fakeS3();
        $this->fakeStreamer();
        $this->createPlanWithScreenshots(0);

        $this->fakeLogin();
        $media = $this->createAccessibleMedia();

        $this->askWithImage($media)
            ->assertStatus(200)
            ->assertHeaderMissing('X-RateLimit-Remaining');
    }
}
