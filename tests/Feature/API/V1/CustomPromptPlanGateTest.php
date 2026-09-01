<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\CustomPrompt;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 自訂 AI 摘要的方案門檻。
 *
 * 判準是 plans.custom_summary_enabled——Free 為 false，Pro 與 Advance 為 true。
 *
 * @internal
 * @coversNothing
 */
class CustomPromptPlanGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建立「沒有訂閱時的預設方案」：SubscriptionService 是用「有一筆月費 0 的
     * 價格」認免費方案的，所以 fixture 必須長成那樣。
     */
    private function defaultPlan(bool $customSummaryEnabled): Plan
    {
        $plan = Plan::factory()->create([
            'title'                  => $customSummaryEnabled ? 'Pro' : 'Free',
            'custom_summary_enabled' => $customSummaryEnabled,
            'sort'                   => 0,
        ]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);

        return $plan;
    }

    private function payload(): array
    {
        return ['title' => '學習筆記摘要', 'content' => '請整理重點。'];
    }

    public function testStoreIsBlockedWithoutTheFeature(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(false);

        $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload())
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']]);
    }

    public function testStoreIsAllowedWithTheFeature(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(true);

        $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload())
            ->assertStatus(201);
    }

    public function testStoreIsBlockedWhenNoPlanCanBeResolved(): void
    {
        $this->fakeLogin();

        // 無從判斷權益時的預設是不給。
        $this->json('POST', route('api.v1.custom-prompts.store'), $this->payload())
            ->assertStatus(422);
    }

    public function testUpdateIsBlockedWithoutTheFeature(): void
    {
        $user = $this->fakeLogin();
        $this->defaultPlan(false);

        $prompt = CustomPrompt::create([
            'user_id' => $user->getKey(),
            'title'   => '既有設定',
            'content' => '內容',
        ]);

        $uri = route('api.v1.custom-prompts.update', ['promptId' => $prompt->getKey()]);

        $this->json('PUT', $uri, $this->payload())->assertStatus(422);
    }

    public function testPreviewIsBlockedWithoutTheFeature(): void
    {
        $this->fakeLogin();
        $this->defaultPlan(false);

        // 試跑每次都會真的送推論，不擋等於讓免費方案花掉我們的成本。
        $this->json('POST', route('api.v1.custom-prompts.preview.store'), [
            'media_id' => '01k9v7m2q8n4r6t0w3y5z7b1c9',
            'content'  => '請整理重點。',
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['plan']]);
    }

    public function testReadingAndDeletingStayOpenAfterADowngrade(): void
    {
        $user = $this->fakeLogin();
        $this->defaultPlan(false);

        $prompt = CustomPrompt::create([
            'user_id' => $user->getKey(),
            'title'   => '付費時建立的設定',
            'content' => '內容',
        ]);

        // 付費過又降級的人，仍要看得到也刪得掉自己的資料。
        $this->json('GET', route('api.v1.custom-prompts.index'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->json('GET', route('api.v1.custom-prompts.show', ['promptId' => $prompt->getKey()]))
            ->assertStatus(200);

        $this->json('DELETE', route('api.v1.custom-prompts.destroy', ['promptId' => $prompt->getKey()]))
            ->assertStatus(200);
    }

    public function testOtherUsersAreUnaffected(): void
    {
        $this->defaultPlan(true);

        $other = User::factory()->create();
        CustomPrompt::create(['user_id' => $other->getKey(), 'title' => 'x', 'content' => 'y']);

        $this->fakeLogin();

        $this->json('GET', route('api.v1.custom-prompts.index'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
