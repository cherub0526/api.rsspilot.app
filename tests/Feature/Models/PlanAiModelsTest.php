<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\AiModel;
use Database\Seeders\PlanAiModelSeeder;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * Plan ↔ AiModel 的多對多。
 *
 * @internal
 * @coversNothing
 */
class PlanAiModelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 型錄由 migration 預先 seed 了 8 個模型，那會讓任何「數量」斷言都對不上。
     * 這個類別只在乎關聯行為，先清空再造自己要的資料。
     */
    protected function setUp(): void
    {
        parent::setUp();

        AiModel::query()->forceDelete();
    }

    public function testPlanCanReachItsModels(): void
    {
        $plan = Plan::factory()->create();
        $model = AiModel::factory()->create(['name' => '指定模型']);

        $plan->aiModels()->sync([$model->getKey()]);

        $this->assertSame(['指定模型'], $plan->aiModels()->pluck('name')->all());
    }

    public function testTheRelationIsReversible(): void
    {
        $plan = Plan::factory()->create(['title' => '某方案']);
        $model = AiModel::factory()->create();

        $plan->aiModels()->sync([$model->getKey()]);

        $this->assertSame(['某方案'], $model->plans()->pluck('title')->all());
    }

    public function testAttachingGeneratesAUlidPivotKey(): void
    {
        $plan = Plan::factory()->create();
        $model = AiModel::factory()->create();

        // 中介表主鍵是 ULID，原生的 attach() 不會產生 id、直接違反 NOT NULL。
        $plan->aiModels()->attach($model->getKey());

        $this->assertSame(1, $plan->aiModels()->count());
    }

    public function testTheSamePairCannotBeAttachedTwice(): void
    {
        $plan = Plan::factory()->create();
        $model = AiModel::factory()->create();

        $plan->aiModels()->sync([$model->getKey()]);
        $plan->aiModels()->sync([$model->getKey()]);

        $this->assertSame(1, $plan->aiModels()->count());
    }

    public function testTheSeederGivesHigherPlansEverythingLowerPlansHave(): void
    {
        // plans 由 PlanPriceSeeder 建立，測試環境沒有那三筆，所以自己造。
        $plans = collect(['Free', 'Pro', 'Advance'])
            ->mapWithKeys(fn (string $title): array => [$title => Plan::factory()->create(['title' => $title])]);

        // seeder 是照 provider_model 指名的，fixture 必須用真實的代號。
        foreach (['openai/gpt-4.1-mini', 'openai/gpt-5', 'anthropic/claude-opus-5'] as $providerModel) {
            AiModel::factory()->create(['provider_model' => $providerModel, 'enabled' => true]);
        }

        (new PlanAiModelSeeder())->run();

        $free = $plans['Free']->aiModels()->pluck('ai_models.id')->all();
        $pro = $plans['Pro']->aiModels()->pluck('ai_models.id')->all();
        $advance = $plans['Advance']->aiModels()->pluck('ai_models.id')->all();

        $this->assertCount(1, $free);
        $this->assertCount(2, $pro);
        $this->assertCount(3, $advance);
        $this->assertEmpty(array_diff($free, $pro), 'Pro 應包含 Free 的所有模型');
        $this->assertEmpty(array_diff($pro, $advance), 'Advance 應包含 Pro 的所有模型');
    }

    public function testTheSeederDoesNotOverwriteAnExistingMapping(): void
    {
        $plan = Plan::factory()->create(['title' => 'Free']);
        $curated = AiModel::factory()->create(['provider_model' => 'anthropic/claude-opus-5', 'enabled' => true]);
        AiModel::factory()->create(['provider_model' => 'openai/gpt-4.1-mini', 'enabled' => true]);

        // 有人把旗艦模型單獨開放給 Free——那是刻意的調整，重跑不該洗掉。
        $plan->aiModels()->sync([$curated->getKey()]);

        (new PlanAiModelSeeder())->run();

        $this->assertSame([(string) $curated->getKey()], $plan->aiModels()->pluck('ai_models.id')->all());
    }

    public function testTheSeederSkipsDisabledModels(): void
    {
        $plan = Plan::factory()->create(['title' => 'Free']);
        AiModel::factory()->disabled()->create(['provider_model' => 'openai/gpt-4.1-mini']);

        (new PlanAiModelSeeder())->run();

        $this->assertSame(0, $plan->aiModels()->count());
    }
}
