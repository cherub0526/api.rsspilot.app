<?php

declare(strict_types=1);

namespace App\Services\FollowUpQuestions;

use NeuronAI\Agent\Agent;
use Hypervel\Support\Facades\Log;
use App\Utils\AI\OpenRouterModels;
use NeuronAI\Chat\Messages\Message;
use App\Utils\AI\OpenRouterProvider;
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

    public function generate(string $answers, string $language): array
    {
        $requested = OpenRouterModels::for(self::class);

        $message = Agent::make()
            ->setAiProvider($this->provider ?? $this->defaultProvider($requested))
            ->chat(new UserMessage($this->template->build($answers, $language)))
            ->getMessage();

        $this->logRouting($requested, $message);

        return $this->parser->parse($message->getContent());
    }

    protected function defaultProvider(string $model): AIProviderInterface
    {
        return new OpenRouterProvider(
            baseUri: (string) config('ai.openrouter.base_uri'),
            key: (string) config('ai.openrouter.api_key'),
            model: $model,
        );
    }

    /**
     * 走 Auto Router 時把「實際跑了哪個模型、用了多少 token」記下來。
     *
     * 指定單一模型時不記：那種情況下實際模型恆等於要求的模型，每次呼叫都寫一行
     * 只是把 log 灌滿。這條路徑不扣 chat 額度、成本靠 throttle 擋，所以帳單上的
     * 異常只能靠這行事後追。
     *
     * 注入替身的測試不會帶 metadata，所以 actual 允許是 null——沒有它也不該讓
     * 產生延伸問題這件事失敗。
     */
    private function logRouting(string $requested, Message $message): void
    {
        if (!str_starts_with($requested, 'openrouter/auto')) {
            return;
        }

        $usage = $message->getUsage();

        Log::info('follow-up questions routed by openrouter auto', [
            'requested'     => $requested,
            'actual'        => $message->getMetadata(OpenRouterProvider::META_MODEL),
            'input_tokens'  => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
        ]);
    }
}
