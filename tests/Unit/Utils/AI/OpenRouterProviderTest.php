<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Tests\TestCase;
use App\Utils\AI\OpenRouterProvider;
use NeuronAI\Providers\OpenAI\StreamState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;

/**
 * OpenRouterProvider 多做兩件事：把回應裡的 model 留在 metadata 上，以及把
 * 串流中的 delta.reasoning 轉成 ReasoningChunk。
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

    // ── 串流：思考過程 ───────────────────────────────

    /**
     * NeuronAI 的 OpenAI 版只讀 delta.content，OpenRouter 把推理內容放在
     * delta.reasoning——不接這個欄位的話，思考過程在最底層就沒了。
     */
    public function testTurnsTheReasoningDeltaIntoItsOwnChunk(): void
    {
        $chunks = $this->deltaChunks(['delta' => ['reasoning' => '先看一下逐字稿']]);

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(ReasoningChunk::class, $chunks[0]);
        $this->assertSame('先看一下逐字稿', $chunks[0]->content);
    }

    /** 同一個 delta 同時有推理與回答時，推理要排在前面。 */
    public function testReasoningComesBeforeTheAnswer(): void
    {
        $chunks = $this->deltaChunks([
            'index' => 0,
            'delta' => ['reasoning' => '想一下', 'content' => '答案'],
        ]);

        $this->assertCount(2, $chunks);
        $this->assertInstanceOf(ReasoningChunk::class, $chunks[0]);
        $this->assertInstanceOf(TextChunk::class, $chunks[1]);
    }

    /**
     * reasoning_details 是 reasoning 的結構化版本，同一段內容兩邊都會出現，
     * 兩個都收會讓思考過程整段重複。
     */
    public function testDoesNotDoubleCountTheStructuredReasoning(): void
    {
        $chunks = $this->deltaChunks([
            'delta' => [
                'reasoning'         => '想一下',
                'reasoning_details' => [['type' => 'reasoning.text', 'text' => '想一下']],
            ],
        ]);

        $this->assertCount(1, $chunks);
        $this->assertSame('想一下', $chunks[0]->content);
    }

    /** 只給結構化欄位的模型也要讀得到。 */
    public function testReadsTheStructuredReasoningWhenItIsTheOnlyField(): void
    {
        $chunks = $this->deltaChunks([
            'delta' => [
                'reasoning_details' => [
                    ['type' => 'reasoning.text', 'text' => '第一段'],
                    ['type' => 'reasoning.text', 'text' => '第二段'],
                ],
            ],
        ]);

        $this->assertCount(2, $chunks);
        $this->assertSame('第一段', $chunks[0]->content);
        $this->assertSame('第二段', $chunks[1]->content);
    }

    /** 不會思考的模型維持原本行為：只有一個 TextChunk。 */
    public function testPlainModelsStillYieldTextOnly(): void
    {
        $chunks = $this->deltaChunks(['index' => 0, 'delta' => ['content' => '答案']]);

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(TextChunk::class, $chunks[0]);
    }

    /**
     * 推理內容不能進到 content block——那是在組最終的 AssistantMessage，進去就會
     * 變成回答的一部分，落庫與送回模型的歷史都會被污染。
     */
    public function testReasoningNeverBecomesPartOfTheAnswer(): void
    {
        $state = new StreamState();

        $this->deltaChunks(['delta' => ['reasoning' => '想一下']], $state);

        $this->assertSame([], $state->getContentBlocks());
    }

    /**
     * 直接餵一個 choice 給串流的 delta 處理，取回它吐出來的 chunk。
     *
     * processContentDelta() 是 protected 的串流 hook，而串流本身要有連線才跑得
     * 起來——用匿名子類別把它開出來，是不發請求就測到它的唯一辦法。
     *
     * @param array<string, mixed> $choice
     * @return array<int, mixed>
     */
    private function deltaChunks(array $choice, ?StreamState $state = null): array
    {
        $provider = new class('https://openrouter.ai/api/v1', 'key', 'openrouter/auto') extends OpenRouterProvider {
            /**
             * @param array<string, mixed> $choice
             * @return array<int, mixed>
             */
            public function exposeDelta(array $choice, StreamState $state): array
            {
                $this->streamState = $state;

                return iterator_to_array($this->processContentDelta($choice), false);
            }
        };

        return $provider->exposeDelta($choice, $state ?? new StreamState());
    }
}
