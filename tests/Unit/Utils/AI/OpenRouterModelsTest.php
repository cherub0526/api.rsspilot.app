<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Tests\TestCase;
use App\Models\Config;
use App\Utils\AI\OpenRouterModels;
use App\Utils\AI\NeuronChatStreamer;
use App\Services\Prompts\SummaryTemplate;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\FollowUpQuestions\NeuronFollowUpQuestions;

/**
 * @internal
 * @coversNothing
 */
class OpenRouterModelsTest extends TestCase
{
    use RefreshDatabase;

    public function testKeysUseSlashesSoTheStoredJsonNeedsNoEscaping(): void
    {
        $default = (string) config('ai.default_model');

        $this->assertSame([
            'App/Utils/AI/NeuronChatStreamer'                        => $default,
            'App/Services/FollowUpQuestions/NeuronFollowUpQuestions' => $default,
            'App/Services/Prompts/SummaryTemplate'                   => $default,
            'App/Services/Prompts/CustomPromptTemplate'              => $default,
        ], OpenRouterModels::defaults());
    }

    /**
     * 遷移就該把整張表寫進去，程式跑起來不必先手動同步。
     */
    public function testTheMigrationSeedsTheConfigRow(): void
    {
        $this->assertSame(
            OpenRouterModels::defaults(),
            Config::getValue(Config::KEY_OPENROUTER_MODELS)
        );
    }

    public function testSyncWritesTheWholeTableIntoConfigs(): void
    {
        Config::query()->where('key', Config::KEY_OPENROUTER_MODELS)->delete();

        OpenRouterModels::sync();

        $this->assertSame(
            OpenRouterModels::defaults(),
            Config::getValue(Config::KEY_OPENROUTER_MODELS)
        );
    }

    /**
     * 重跑遷移／再次同步不該把線上調過的模型蓋回預設。
     */
    public function testSyncKeepsExistingValuesAndOnlyFillsMissingKeys(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Utils/AI/NeuronChatStreamer' => 'anthropic/claude-sonnet-4',
        ]);

        $merged = OpenRouterModels::sync();

        $this->assertSame('anthropic/claude-sonnet-4', $merged['App/Utils/AI/NeuronChatStreamer']);
        $this->assertSame(
            (string) config('ai.default_model'),
            $merged['App/Services/Prompts/SummaryTemplate']
        );
    }

    /**
     * 用途改名或下架後，舊 key 留在表裡只會誤導讀表的人。
     */
    public function testSyncDropsKeysThatAreNoLongerInTheClassList(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Services/Prompts/TemplateCompletionManager' => 'anthropic/claude-sonnet-4',
        ]);

        $merged = OpenRouterModels::sync();

        $this->assertArrayNotHasKey('App/Services/Prompts/TemplateCompletionManager', $merged);
        $this->assertSame($merged, Config::getValue(Config::KEY_OPENROUTER_MODELS));
    }

    public function testForReadsThePerClassModelFromConfigs(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Utils/AI/NeuronChatStreamer'                        => 'anthropic/claude-sonnet-4',
            'App/Services/FollowUpQuestions/NeuronFollowUpQuestions' => 'google/gemini-2.5-flash',
        ]);

        $this->assertSame(
            'anthropic/claude-sonnet-4',
            OpenRouterModels::for(NeuronChatStreamer::class)
        );
        $this->assertSame(
            'google/gemini-2.5-flash',
            OpenRouterModels::for(NeuronFollowUpQuestions::class)
        );
    }

    /**
     * 沒設定的 class 要能直接跑，不必等 sync() 補資料。
     */
    public function testForFallsBackToTheDefaultModelWhenTheKeyIsMissing(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Utils/AI/NeuronChatStreamer' => 'anthropic/claude-sonnet-4',
        ]);

        $this->assertSame(
            (string) config('ai.default_model'),
            OpenRouterModels::for(SummaryTemplate::class)
        );
    }

    public function testForFallsBackWhenTheConfigRowDoesNotExist(): void
    {
        Config::query()->where('key', Config::KEY_OPENROUTER_MODELS)->delete();

        $this->assertSame(
            (string) config('ai.default_model'),
            OpenRouterModels::for(NeuronChatStreamer::class)
        );
    }

    public function testForFallsBackWhenTheStoredValueIsBlank(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Utils/AI/NeuronChatStreamer' => '',
        ]);

        $this->assertSame(
            (string) config('ai.default_model'),
            OpenRouterModels::for(NeuronChatStreamer::class)
        );
    }
}
