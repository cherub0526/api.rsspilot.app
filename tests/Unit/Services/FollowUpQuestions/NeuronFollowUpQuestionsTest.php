<?php

declare(strict_types=1);

namespace Tests\Unit\Services\FollowUpQuestions;

use Tests\TestCase;
use App\Models\Config;
use Hypervel\Support\Facades\Log;
use Tests\Support\FakeAIProvider;
use App\Utils\AI\OpenRouterProvider;
use NeuronAI\Chat\Messages\AssistantMessage;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\FollowUpQuestions\NeuronFollowUpQuestions;
use App\Services\FollowUpQuestions\FollowUpQuestionsTemplate;

/**
 * @internal
 * @coversNothing
 */
class NeuronFollowUpQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function testGenerateReturnsTheThreeParsedQuestions(): void
    {
        $provider = new FakeAIProvider("### 1. 均線怎麼用？\n### 2. 族群怎麼選？\n### 3. 停損怎麼設？");

        $questions = (new NeuronFollowUpQuestions($provider))->generate('前一輪的回答內容', 'English');

        $this->assertSame(['均線怎麼用？', '族群怎麼選？', '停損怎麼設？'], $questions);
    }

    /**
     * 兩個後端要送出逐字相同的提示詞，產出才有比較意義。指示不拆進 system，
     * 而是連同回答一起當成單一使用者訊息 —— 與 videotranscriber 只收一個
     * `text` 欄位的形狀對齊。
     */
    public function testGenerateSendsTheSamePromptAsTheVideoTranscriberVersion(): void
    {
        $provider = new FakeAIProvider('### 1. a');

        (new NeuronFollowUpQuestions($provider))->generate('前一輪的回答內容', 'English');

        $sent = array_map(
            fn ($message): string => $message->getContent(),
            $provider->received
        );

        $this->assertSame(
            [(new FollowUpQuestionsTemplate())->build('前一輪的回答內容', 'English')],
            $sent,
            '送出的內容必須與模板組出的完全一致，且只有一則訊息'
        );
    }

    public function testGenerateReturnsEmptyArrayWhenTheModelIgnoresTheFormat(): void
    {
        $provider = new FakeAIProvider('抱歉，我無法產生問題。');

        $this->assertSame([], (new NeuronFollowUpQuestions($provider))->generate('回答', 'English'));
    }

    /**
     * 指定單一模型時不記 log：實際模型恆等於要求的模型，每次都寫一行只是灌滿 log。
     */
    public function testGenerateDoesNotLogRoutingForAPinnedModel(): void
    {
        $this->setFollowUpModel('openai/gpt-4.1-mini');

        Log::shouldReceive('info')->never();

        (new NeuronFollowUpQuestions(new FakeAIProvider('### 1. a')))->generate('回答', 'English');
    }

    /**
     * 走 auto 時記下實際模型與 token 用量——這是事後回答「auto 選了什麼、花了多少」
     * 的唯一依據。
     */
    public function testGenerateLogsTheActualModelWhenRoutedByAuto(): void
    {
        $this->setFollowUpModel('openrouter/auto');

        $reply = (new AssistantMessage('### 1. a'))
            ->addMetadata(OpenRouterProvider::META_MODEL, 'anthropic/claude-haiku-4.5');

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'openrouter auto')
                    && $context['requested'] === 'openrouter/auto'
                    && $context['actual'] === 'anthropic/claude-haiku-4.5';
            });

        (new NeuronFollowUpQuestions(new FakeAIProvider('', $reply)))->generate('回答', 'English');
    }

    /**
     * 拿不到 metadata（模型沒回報、或注入的是不帶 metadata 的替身）時 actual 是 null，
     * 但延伸問題本身照樣要產得出來——記錄失敗不該讓功能失敗。
     */
    public function testGenerateStillWorksWhenTheActualModelIsUnknown(): void
    {
        $this->setFollowUpModel('openrouter/auto');

        $questions = (new NeuronFollowUpQuestions(new FakeAIProvider('### 1. 只有這題')))
            ->generate('回答', 'English');

        $this->assertSame(['只有這題'], $questions);
    }

    private function setFollowUpModel(string $model): void
    {
        Config::setValue(Config::KEY_OPENROUTER_MODELS, [
            'App/Services/FollowUpQuestions/NeuronFollowUpQuestions' => $model,
        ]);
    }
}
