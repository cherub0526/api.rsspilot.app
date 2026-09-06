<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Tests\TestCase;
use App\Utils\AI\OpenRouterProvider;
use NeuronAI\Chat\Messages\AssistantMessage;

/**
 * OpenRouterProvider 只多做一件事：把回應裡的 model 留在 metadata 上。
 *
 * processChatResult() 是 protected，這裡用匿名子類把它開出來測——直接發真的
 * HTTP 請求才測得到的話，這個覆寫就等於沒有測試。
 *
 * @internal
 * @coversNothing
 */
class OpenRouterProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $result
     */
    private function process(array $result): AssistantMessage
    {
        $provider = new class('https://openrouter.ai/api/v1', 'key', 'openrouter/auto') extends OpenRouterProvider {
            /**
             * @param array<string, mixed> $result
             */
            public function expose(array $result): AssistantMessage
            {
                return $this->processChatResult($result);
            }
        };

        return $provider->expose($result);
    }

    /**
     * @param null|string $model 傳 null 代表回應裡根本沒有 model 欄位
     * @return array<string, mixed>
     */
    private function response(?string $model, string $content = '回答'): array
    {
        $result = [
            'choices' => [
                ['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]],
            ],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
        ];

        if ($model !== null) {
            $result['model'] = $model;
        }

        return $result;
    }

    // ================================================================

    /**
     * 走 auto 時「要求的模型」永遠是 openrouter/auto，實際跑的是哪一個只有回應知道。
     */
    public function testKeepsTheActualModelFromTheResponse(): void
    {
        $message = $this->process($this->response('anthropic/claude-haiku-4.5'));

        $this->assertSame(
            'anthropic/claude-haiku-4.5',
            $message->getMetadata(OpenRouterProvider::META_MODEL)
        );
    }

    /**
     * 覆寫不能吃掉父類別本來就會做的事。
     */
    public function testStillParsesContentAndUsage(): void
    {
        $message = $this->process($this->response('openai/gpt-4.1-mini', '這是回答'));

        $this->assertSame('這是回答', $message->getContent());
        $this->assertSame(120, $message->getUsage()?->inputTokens);
        $this->assertSame(30, $message->getUsage()?->outputTokens);
    }

    /**
     * 回應沒有 model 欄位時留 null，不要寫入空字串——呼叫端靠 null 分辨「不知道」。
     */
    public function testLeavesMetadataUnsetWhenTheResponseHasNoModel(): void
    {
        $this->assertNull(
            $this->process($this->response(null))->getMetadata(OpenRouterProvider::META_MODEL)
        );
    }

    public function testLeavesMetadataUnsetWhenTheModelIsAnEmptyString(): void
    {
        $this->assertNull(
            $this->process($this->response(''))->getMetadata(OpenRouterProvider::META_MODEL)
        );
    }
}
