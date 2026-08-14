<?php

declare(strict_types=1);

namespace App\Services\FollowUpQuestions;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

/**
 * 透過 NeuronAI（OpenRouter）產生延伸問題。
 *
 * 與 VideoTranscriberFollowUpQuestions 送出「逐字相同」的提示詞，兩者的產出
 * 才有比較意義 —— 所以指示不拆進 setInstructions()，而是連同回答一起當成單一
 * 使用者訊息送出，跟 videotranscriber 只收一個 `text` 欄位的形狀對齊。
 *
 * 用 OpenAILike 而不是 OpenAI：NeuronAI 沒有內建 OpenRouter provider，
 * 而 OpenRouter 是 OpenAI 相容 API。同 NeuronChatStreamer。
 *
 * provider 可注入，預設才依 config 建立 —— NeuronAI 走自建的 Guzzle client，
 * Http::fake() 攔不到，測試需要這個縫。
 */
class NeuronFollowUpQuestions implements FollowUpQuestionsGeneratorInterface
{
    public function __construct(
        protected ?AIProviderInterface $provider = null,
        protected FollowUpQuestionsTemplate $template = new FollowUpQuestionsTemplate(),
        protected FollowUpQuestionsParser $parser = new FollowUpQuestionsParser(),
    ) {
    }

    public function generate(string $answers): array
    {
        $message = Agent::make()
            ->setAiProvider($this->provider ?? $this->defaultProvider())
            ->chat(new UserMessage($this->template->build($answers)))
            ->getMessage();

        return $this->parser->parse($message->getContent());
    }

    protected function defaultProvider(): AIProviderInterface
    {
        return new OpenAILike(
            baseUri: (string) config('ai.openrouter.base_uri'),
            key: (string) config('ai.openrouter.api_key'),
            model: (string) config('ai.default_model'),
        );
    }
}
