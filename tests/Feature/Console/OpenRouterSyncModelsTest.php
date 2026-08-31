<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\AiModel;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * openrouter:sync-models.
 *
 * @internal
 * @coversNothing
 */
class OpenRouterSyncModelsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCatalogue(array $models): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['data' => $models], 200),
        ]);
    }

    private function model(array $overrides = []): array
    {
        return array_merge([
            'id'                   => 'vendor/model-a',
            'name'                 => 'Vendor: Model A',
            'pricing'              => ['prompt' => '0.0000004', 'completion' => '0.0000016'],
            'supported_parameters' => [],
        ], $overrides);
    }

    public function testNewModelsArriveDisabled(): void
    {
        $this->fakeCatalogue([$this->model()]);

        $this->artisan('openrouter:sync-models')->assertExitCode(0);

        $model = AiModel::query()->where('provider_model', 'vendor/model-a')->first();

        $this->assertNotNull($model);
        // 型錄有 300+ 個模型，自動全開會讓使用者的下拉選單無法使用。
        $this->assertFalse($model->enabled);
    }

    public function testPricesAreStoredPerMillionTokens(): void
    {
        $this->fakeCatalogue([$this->model()]);

        $this->artisan('openrouter:sync-models');

        $model = AiModel::query()->where('provider_model', 'vendor/model-a')->first();

        $this->assertSame('0.400000', $model->input_price);
        $this->assertSame('1.600000', $model->output_price);
    }

    public function testThinkingIsDetectedFromTheReasoningParameter(): void
    {
        $this->fakeCatalogue([
            $this->model(['id' => 'vendor/thinker', 'supported_parameters' => ['reasoning', 'include_reasoning']]),
            // 只宣告 include_reasoning 不算——那是「回應要不要帶出思考內容」的開關。
            $this->model(['id' => 'vendor/not-thinker', 'supported_parameters' => ['include_reasoning']]),
        ]);

        $this->artisan('openrouter:sync-models');

        $this->assertTrue(AiModel::query()->where('provider_model', 'vendor/thinker')->first()->supports_thinking);
        $this->assertFalse(AiModel::query()->where('provider_model', 'vendor/not-thinker')->first()->supports_thinking);
    }

    public function testDisplayNameDropsTheVendorPrefix(): void
    {
        $this->fakeCatalogue([$this->model(['name' => 'Anthropic: Claude Sonnet 5'])]);

        $this->artisan('openrouter:sync-models');

        $this->assertSame(
            'Claude Sonnet 5',
            AiModel::query()->where('provider_model', 'vendor/model-a')->first()->name
        );
    }

    public function testSyncDoesNotOverwriteEnabledOrPlanAccess(): void
    {
        $existing = AiModel::factory()->create([
            'provider_model' => 'vendor/model-a',
            'enabled'        => true,
        ]);
        $plan = Plan::factory()->create();
        $plan->aiModels()->sync([$existing->getKey()]);

        $this->fakeCatalogue([$this->model(['name' => 'Vendor: Renamed'])]);

        $this->artisan('openrouter:sync-models');

        $fresh = $existing->fresh();

        // 供應商說了算的只有名稱與價格；開放與否、掛在哪個方案都是產品決定。
        $this->assertTrue($fresh->enabled);
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame(1, $fresh->plans()->count());
    }

    public function testModelsMissingFromTheCatalogueAreDisabledNotDeleted(): void
    {
        $gone = AiModel::factory()->create(['provider_model' => 'vendor/retired', 'enabled' => true]);

        $this->fakeCatalogue([$this->model()]);

        $this->artisan('openrouter:sync-models');

        $fresh = $gone->fresh();

        // 刪掉的話，已經選了它的 custom_prompts 會指向不存在的 id。
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->enabled);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->fakeCatalogue([$this->model()]);

        $this->artisan('openrouter:sync-models', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, AiModel::query()->where('provider_model', 'vendor/model-a')->count());
    }

    public function testFailedRequestExitsNonZeroAndWritesNothing(): void
    {
        // 型錄由 migration 預先寫入，所以比的是「有沒有新增」而不是「是不是空的」。
        $before = AiModel::query()->count();

        Http::fake(['openrouter.ai/*' => Http::response('', 503)]);

        $this->artisan('openrouter:sync-models')->assertExitCode(1);

        $this->assertSame($before, AiModel::query()->count());
    }
}
