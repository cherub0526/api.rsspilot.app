<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\Price;
use App\Models\AiModel;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class AiModelsControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建立「沒有訂閱時的預設方案」。
     *
     * SubscriptionService::getUserSubscriptionPlan() 在查不到訂閱時，是用
     * 「有一筆月費 0 的價格」來認免費方案的，所以 fixture 必須長成那樣。
     */
    private function freePlan(array $models = []): Plan
    {
        $plan = Plan::factory()->create(['title' => 'Free', 'sort' => 0]);
        $plan->prices()->create(['unit' => Price::UNIT_MONTHLY, 'price' => 0]);

        if ($models !== []) {
            $plan->aiModels()->sync($models);
        }

        return $plan;
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->json('GET', route('api.v1.ai-models.index'))->assertStatus(401);
    }

    public function testIndexReturnsOnlyTheModelsThePlanAllows(): void
    {
        $this->fakeLogin();

        $allowed = AiModel::factory()->create(['name' => '方案內的模型']);
        AiModel::factory()->create(['name' => '方案外的模型']);

        $this->freePlan([$allowed->getKey()]);

        $names = array_column($this->json('GET', route('api.v1.ai-models.index'))->json('data'), 'name');

        // 型錄裡還有 migration seed 的 8 個模型，它們沒掛在這個方案上，同樣不該出現。
        $this->assertSame(['方案內的模型'], $names);
    }

    public function testMinPlanIsDerivedFromThePivot(): void
    {
        $this->fakeLogin();

        $model = AiModel::factory()->create();
        $plan = $this->freePlan([$model->getKey()]);

        $this->json('GET', route('api.v1.ai-models.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.min_plan.title', $plan->title);
    }

    public function testIndexIsEmptyWhenNoPlanCanBeResolved(): void
    {
        $this->fakeLogin();
        AiModel::factory()->create();

        // 連免費方案都查不到時「不知道誰能用什麼」，安全的預設是不給。
        $this->json('GET', route('api.v1.ai-models.index'))
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function testIndexHidesDisabledModels(): void
    {
        $this->fakeLogin();

        $hidden = AiModel::factory()->disabled()->create(['name' => '已下架的模型']);
        $this->freePlan([$hidden->getKey()]);

        $names = array_column($this->json('GET', route('api.v1.ai-models.index'))->json('data'), 'name');

        $this->assertNotContains('已下架的模型', $names);
        $this->assertTrue($hidden->exists);
    }

    public function testIndexDoesNotLeakTheProviderModel(): void
    {
        $this->fakeLogin();

        // provider_model 是路由到供應商的內部代號，不該出現在對外輸出裡。
        $content = $this->json('GET', route('api.v1.ai-models.index'))->getContent();

        $this->assertStringNotContainsString('provider_model', $content);
        $this->assertStringNotContainsString('anthropic/', $content);
    }
}
