<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prompts;

use Tests\TestCase;
use App\Models\Config;
use App\Utils\AI\Completion;
use Hypervel\Support\Facades\Http;
use App\Services\Prompts\TemplateFactory;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\Prompts\TemplateCompletionManager;

/**
 * summary 與 customPrompt 共用 TemplateCompletionManager，模型卻是各自的設定 ——
 * key 掛在模板 class 上，所以換一個不會動到另一個。
 *
 * @internal
 * @coversNothing
 */
class TemplateCompletionManagerModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{}']]],
            ], 200),
        ]);
    }

    private function complete(string $type): void
    {
        $template = TemplateFactory::create($type, [
            'language'         => 'English',
            'system_prompt'    => 'p',
            'user_prompt'      => 'u',
            'respond_language' => 'English',
        ]);

        (new TemplateCompletionManager(Completion::make(), $template))->complete('逐字稿');
    }

    private function assertModelSent(string $expected): void
    {
        Http::assertSent(function ($request) use ($expected) {
            $this->assertSame($expected, $request->data()['model']);

            return true;
        });
    }

    public function testSummaryUsesItsOwnModel(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Services/Prompts/SummaryTemplate'      => 'anthropic/claude-sonnet-4',
            'App/Services/Prompts/CustomPromptTemplate' => 'google/gemini-2.5-flash',
        ]);

        $this->complete('summary');

        $this->assertModelSent('anthropic/claude-sonnet-4');
    }

    public function testCustomPromptUsesItsOwnModel(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Services/Prompts/SummaryTemplate'      => 'anthropic/claude-sonnet-4',
            'App/Services/Prompts/CustomPromptTemplate' => 'google/gemini-2.5-flash',
        ]);

        $this->complete('customPrompt');

        $this->assertModelSent('google/gemini-2.5-flash');
    }

    /**
     * 沒登記的模板照樣能跑，退回預設模型。
     */
    public function testAnUnregisteredTemplateFallsBackToTheDefaultModel(): void
    {
        $this->complete('translation');

        $this->assertModelSent((string) config('ai.default_model'));
    }

    /**
     * 呼叫端明講的模型優先，設定不該蓋掉它。
     */
    public function testAnExplicitModelArgumentStillWins(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Services/Prompts/SummaryTemplate' => 'anthropic/claude-sonnet-4',
        ]);

        $template = TemplateFactory::create('summary', ['language' => 'English']);

        (new TemplateCompletionManager(Completion::make(), $template))
            ->complete('逐字稿', 'openai/gpt-4o-mini');

        $this->assertModelSent('openai/gpt-4o-mini');
    }
}
