<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Seeders;

use Tests\TestCase;
use App\Models\Config;
use App\Utils\AI\OpenRouterModels;
use Database\Seeders\OpenRouterModelsSeeder;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class OpenRouterModelsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeder(): void
    {
        $this->seed(OpenRouterModelsSeeder::class);
    }

    public function testItCreatesTheConfigRowWhenThereIsNoValue(): void
    {
        Config::query()->where('key', Config::KEY_OPENROUTER_MODELS)->delete();

        $this->runSeeder();

        $this->assertSame(
            OpenRouterModels::defaults(),
            Config::getValue(Config::KEY_OPENROUTER_MODELS)
        );
    }

    /**
     * 有值但缺 key —— 補上缺的，已經有的原封不動。
     */
    public function testItFillsMissingKeysWithoutTouchingExistingValues(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Services/Prompts/SummaryTemplate' => 'anthropic/claude-sonnet-4',
        ]);

        $this->runSeeder();

        $value = Config::getValue(Config::KEY_OPENROUTER_MODELS);

        $this->assertSame('anthropic/claude-sonnet-4', $value['App/Services/Prompts/SummaryTemplate']);
        $this->assertSame(array_keys(OpenRouterModels::defaults()), array_keys($value));
        $this->assertSame(
            (string) config('ai.default_model'),
            $value['App/Services/Prompts/CustomPromptTemplate']
        );
    }

    /**
     * 舊版的 key 已不在 CLASSES 裡，重跑 seeder 要順手清掉。
     */
    public function testItDropsKeysThatAreNoLongerInUse(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Services/Prompts/TemplateCompletionManager' => 'anthropic/claude-sonnet-4',
        ]);

        $this->runSeeder();

        $this->assertArrayNotHasKey(
            'App/Services/Prompts/TemplateCompletionManager',
            Config::getValue(Config::KEY_OPENROUTER_MODELS)
        );
    }

    /**
     * 可重複執行：跑第二次不該產生第二列，也不該改到任何值。
     */
    public function testItIsIdempotent(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Utils/AI/NeuronChatStreamer' => 'google/gemini-2.5-flash',
        ]);

        $this->runSeeder();
        $expected = Config::getValue(Config::KEY_OPENROUTER_MODELS);

        $this->runSeeder();

        $this->assertSame(1, Config::query()->where('key', Config::KEY_OPENROUTER_MODELS)->count());
        $this->assertSame($expected, Config::getValue(Config::KEY_OPENROUTER_MODELS));
        $this->assertSame('google/gemini-2.5-flash', $expected['App/Utils/AI/NeuronChatStreamer']);
    }
}
