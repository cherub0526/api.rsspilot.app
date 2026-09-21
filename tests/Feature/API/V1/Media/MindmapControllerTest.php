<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\Mindmap;
use App\Models\Setting;
use App\Models\Summary;
use App\Models\MindmapUsage;
use Tests\Support\FakeChatStreamer;
use Tests\Support\FailingChatStreamer;
use App\Utils\AI\ChatStreamerInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 心智圖端點。
 *
 * POST 的回應是 SSE 串流，但與 chat/stream 不同的是它會跑完就結束（不像對話那條
 * 常駐連線會卡在 Channel::pop 等事件），所以串流回呼在 in-process 測試裡會被完整
 * 求值——推論、落庫與失敗退款都測得到。
 *
 * @internal
 * @coversNothing
 */
class MindmapControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ────────────────────────────────────────────────

    private function ownedMedia(User $user): Media
    {
        $media = Media::factory()->create();
        $user->media()->attach($media->id);

        return $media;
    }

    /** 有內容的已完成摘要——心智圖的唯一輸入來源。 */
    private function completedSummary(Media $media, array $attributes = []): Summary
    {
        return Summary::factory()->create(array_merge([
            'media_id' => $media->id,
            'user_id'  => null,
            'locale'   => 'en',
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => [
                'short_summary' => 'Short.',
                'long_summary'  => [
                    'content'    => 'A long summary about running and markets.',
                    'key_points' => ['Ran 10km', 'Bought the dip'],
                    'keywords'   => ['running', 'stocks'],
                ],
            ],
        ], $attributes));
    }

    private function setAiLanguage(User $user, string $code): void
    {
        Setting::create([
            'user_id' => $user->id,
            'data'    => ['ai' => ['language' => $code]],
        ]);
    }

    /**
     * 沒有訂閱的使用者吃的是「月費 0 元」的方案，所以額度測試只要建這個方案即可。
     * withoutEvents 的理由同 ChatQuotaTest：Plan/Price 的 observer 會真的打 Stripe。
     */
    private function createFreePlan(int $mindmapLimit): Plan
    {
        return Plan::withoutEvents(function () use ($mindmapLimit) {
            $plan = Plan::factory()->create([
                'title'         => 'Free',
                'mindmap_limit' => $mindmapLimit,
                'status'        => Plan::STATUS_ACTIVE,
            ]);

            Price::create([
                'plan_id' => $plan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 0,
            ]);

            return $plan;
        });
    }

    private function fakeStreamer(string ...$tokens): FakeChatStreamer
    {
        $streamer = new FakeChatStreamer($tokens === [] ? ["# Title\n"] : $tokens);
        $this->app->instance(ChatStreamerInterface::class, $streamer);

        return $streamer;
    }

    private function show(Media $media)
    {
        return $this->json('GET', route('api.v1.media.mindmap.show', ['mediaId' => $media->id]));
    }

    private function store(Media $media)
    {
        return $this->json('POST', route('api.v1.media.mindmap.store', ['mediaId' => $media->id]));
    }

    // ================================================================
    // GET /v1/media/{mediaId}/mindmap
    // ================================================================

    public function testShowRequiresAuth(): void
    {
        $media = Media::factory()->create();

        $this->show($media)->assertStatus(401);
    }

    public function testShowReturns404WhenUserHasNoAccess(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->show($media)->assertStatus(404);
    }

    /** 沒有摘要就不可能有心智圖 */
    public function testShowReturns404WhenNoSummaryExists(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $this->show($media)->assertStatus(404);
    }

    /**
     * 摘要有了但還沒產生心智圖：404 而不是空陣列。
     * 摘要端點回 `[]` 的做法逼得前端用 Array.isArray() 分辨，不再複製一次。
     */
    public function testShowReturns404WhenMindmapNotGeneratedYet(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $this->completedSummary($media);

        $this->show($media)->assertStatus(404);
    }

    public function testShowReturnsTheMindmap(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);
        $summary = $this->completedSummary($media);

        Mindmap::factory()->create([
            'media_id'   => $media->id,
            'summary_id' => $summary->id,
            'language'   => 'en',
            'markdown'   => "# Title\n## Branch\n- point",
        ]);

        $this->show($media)
            ->assertStatus(200)
            ->assertJsonPath('language', 'en')
            ->assertJsonPath('status', Mindmap::STATUS_COMPLETED)
            ->assertJsonPath('markdown', "# Title\n## Branch\n- point");
    }

    /**
     * 快取鍵帶語言：使用者把 AI 語言換成日文之後，既有的英文版就當作不存在，
     * 前端因此顯示「產生心智圖」而不是拿英文的頂替。
     */
    public function testShowIgnoresAMindmapInAnotherLanguage(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->setAiLanguage($user, 'ja');

        $media = $this->ownedMedia($user);
        $summary = $this->completedSummary($media);

        Mindmap::factory()->create([
            'media_id'   => $media->id,
            'summary_id' => $summary->id,
            'language'   => 'en',
            'markdown'   => '# English',
        ]);

        $this->show($media)->assertStatus(404);
    }

    /**
     * 快取鍵帶 summary_id：摘要重跑之後，掛在舊摘要上的心智圖不該被當成新的。
     * 這是「自訂摘要使用者的心智圖被別人讀到」的同一個防線。
     */
    public function testShowIgnoresAMindmapBuiltFromAnotherSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        $old = $this->completedSummary($media);
        Mindmap::factory()->create([
            'media_id'   => $media->id,
            'summary_id' => $old->id,
            'language'   => 'en',
            'markdown'   => '# Stale',
        ]);

        // 較新的摘要會被 Media::summaryFor() 選中（依 created_at 由新到舊）
        $this->completedSummary($media, ['created_at' => now()->addMinute()]);

        $this->show($media)->assertStatus(404);
    }

    // ================================================================
    // POST /v1/media/{mediaId}/mindmap
    // ================================================================

    public function testStoreRequiresAuth(): void
    {
        $media = Media::factory()->create();

        $this->store($media)->assertStatus(401);
    }

    public function testStoreReturns404WhenUserHasNoAccess(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->store($media)->assertStatus(404);
    }

    /** 摘要未完成 → 422 + code，讓前端分支到「等摘要完成」而不是一般錯誤 */
    public function testStoreReturns422WhenSummaryIsNotReady(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        Summary::factory()->create([
            'media_id' => $media->id,
            'user_id'  => null,
            'locale'   => 'en',
            'status'   => Summary::STATUS_PROCESSING,
        ]);

        $this->store($media)
            ->assertStatus(422)
            ->assertJsonPath('code', 'summary_not_ready')
            ->assertJsonPath('messages.mindmap.0', __('validators.controllers.mindmap.summary_required'));
    }

    /** 摘要已完成但內容是空的，同樣沒有東西可以拿來產生 */
    public function testStoreReturns422WhenSummaryHasNoUsableText(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = $this->ownedMedia($user);

        Summary::factory()->create([
            'media_id' => $media->id,
            'user_id'  => null,
            'locale'   => 'en',
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['long_summary' => ['content' => '   ']],
        ]);

        $this->store($media)
            ->assertStatus(422)
            ->assertJsonPath('code', 'summary_not_ready');
    }

    /** 額度用盡 → 429，而且不會留下 status=processing 的空殼資料列 */
    public function testStoreReturns429WhenDailyQuotaIsExhausted(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(1);
        $this->fakeStreamer();

        $media = $this->ownedMedia($user);
        $this->completedSummary($media);

        MindmapUsage::create([
            'user_id'    => $user->id,
            'quota_date' => now(config('ai.chat.quota_timezone'))->toDateString(),
            'count'      => 1,
        ]);

        $this->store($media)
            ->assertStatus(429)
            ->assertJsonPath('messages.mindmap.0', __('validators.controllers.mindmap.mindmap_limit_reached'));

        $this->assertSame(0, Mindmap::query()->count());
    }

    /** 0 代表不限制，不是「一次都不能產」 */
    public function testStoreAllowsGenerationWhenThePlanIsUnlimited(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(0);
        $this->fakeStreamer();

        $media = $this->ownedMedia($user);
        $this->completedSummary($media);

        MindmapUsage::create([
            'user_id'    => $user->id,
            'quota_date' => now(config('ai.chat.quota_timezone'))->toDateString(),
            'count'      => 99,
        ]);

        $this->store($media)->assertStatus(200);
    }

    /** 進入串流前就扣點，並把資料列建成 processing */
    public function testStoreConsumesQuotaAndCreatesTheRow(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(3);
        $this->fakeStreamer();

        $media = $this->ownedMedia($user);
        $summary = $this->completedSummary($media);

        $this->store($media)->assertStatus(200);

        $usage = MindmapUsage::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($usage);
        $this->assertSame(1, (int) $usage->count);

        $mindmap = Mindmap::query()->first();
        $this->assertNotNull($mindmap);
        $this->assertSame((string) $media->id, (string) $mindmap->media_id);
        $this->assertSame((string) $summary->id, (string) $mindmap->summary_id);
        $this->assertSame('en', $mindmap->language);
        $this->assertNull($mindmap->user_id);
    }

    /** 重新產生走同一支端點，唯一索引上不能撞成兩列 */
    public function testStoreReusesTheRowWhenRegenerating(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(0);
        $this->fakeStreamer();

        $media = $this->ownedMedia($user);
        $summary = $this->completedSummary($media);

        Mindmap::factory()->create([
            'media_id'   => $media->id,
            'summary_id' => $summary->id,
            'language'   => 'en',
            'markdown'   => '# Old',
        ]);

        $this->store($media)->assertStatus(200);

        $this->assertSame(1, Mindmap::query()->count());
    }

    /** 使用者的 AI 語言決定資料列的語言，與介面語系、摘要語言都無關 */
    public function testStoreRecordsTheUserAiLanguage(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->setAiLanguage($user, 'ja');
        $this->createFreePlan(0);
        $this->fakeStreamer();

        $media = $this->ownedMedia($user);
        $this->completedSummary($media, ['locale' => 'en']);

        $this->store($media)->assertStatus(200);

        $this->assertSame('ja', Mindmap::query()->first()?->language);
    }

    /** 串流跑完 → markdown 落庫、狀態轉 completed */
    public function testStoreStoresTheGeneratedMarkdown(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(0);
        $this->fakeStreamer("# Title\n", "## Branch\n", "- point\n");

        $media = $this->ownedMedia($user);
        $this->completedSummary($media);

        $this->store($media)->assertStatus(200);

        $mindmap = Mindmap::query()->first();
        $this->assertSame(Mindmap::STATUS_COMPLETED, $mindmap?->status);
        $this->assertSame("# Title\n## Branch\n- point\n", $mindmap?->markdown);
    }

    /**
     * 一個 token 都沒拿到就失敗 → 退還額度。使用者什麼都沒看到，不該算他用掉一次。
     */
    public function testStoreReleasesQuotaWhenNothingWasGenerated(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(3);
        $this->app->instance(ChatStreamerInterface::class, new FailingChatStreamer());

        $media = $this->ownedMedia($user);
        $this->completedSummary($media);

        $this->store($media)->assertStatus(200);

        $this->assertSame(Mindmap::STATUS_FAILED, Mindmap::query()->first()?->status);
        $this->assertSame(
            0,
            (int) MindmapUsage::query()->where('user_id', $user->id)->value('count')
        );
    }

    /**
     * 已經串出部分內容才失敗 → 額度算用掉：上游 token 已經花了。
     * 與 ChatController 的處理一致。
     */
    public function testStoreKeepsQuotaWhenTheStreamFailedPartway(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->createFreePlan(3);
        $this->app->instance(ChatStreamerInterface::class, new FailingChatStreamer(["# Title\n"]));

        $media = $this->ownedMedia($user);
        $this->completedSummary($media);

        $this->store($media)->assertStatus(200);

        $this->assertSame(Mindmap::STATUS_FAILED, Mindmap::query()->first()?->status);
        $this->assertSame(
            1,
            (int) MindmapUsage::query()->where('user_id', $user->id)->value('count')
        );
    }

    /** 提示詞的語言指示來自 AI 語言，且輸入是摘要的正文 + 重點 + 關鍵字 */
    public function testStoreFeedsTheSummaryAndTheAiLanguageToTheModel(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $this->setAiLanguage($user, 'ja');
        $this->createFreePlan(0);
        $streamer = $this->fakeStreamer();

        $media = $this->ownedMedia($user);
        $this->completedSummary($media);

        $this->store($media)->assertStatus(200);

        $this->assertStringContainsString('All output must be in Japanese', (string) $streamer->instructions);

        $sent = implode("\n", $streamer->contents());
        $this->assertStringContainsString('A long summary about running and markets.', $sent);
        $this->assertStringContainsString('Ran 10km', $sent);
        $this->assertStringContainsString('running, stocks', $sent);
        // short_summary 與 content 內容重複，餵進去只會讓模型產出重複的分支
        $this->assertStringNotContainsString('Short.', $sent);
    }
}
