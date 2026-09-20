<?php

declare(strict_types=1);

namespace App\Utils\AI;

use Generator;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;

/**
 * OpenAILike 再加兩件事：把 OpenRouter 回報的**實際模型**留在訊息的 metadata 上，
 * 以及把會思考的模型送出的推理內容轉成 ReasoningChunk 流出去。
 *
 * NeuronAI 的 `HandleChat::processChatResult()` 只從回應取 `usage` 與 citations，
 * `model` 那個欄位直接丟掉。指定單一模型時無所謂——要求的就是拿到的。但走
 * `openrouter/auto` 時「要求的模型」永遠是字串 `openrouter/auto`，實際跑的是哪一個
 * 只有回應裡的 `model` 知道，丟掉就再也回答不了「auto 幫我選了什麼、花了多少」。
 *
 * 覆寫而不是換掉整個傳輸層，是因為只缺這一個欄位：Completion 那條路雖然拿得到
 * 整包 JSON，但要連 Agent、訊息映射與串流一起自己重寫。
 */
class OpenRouterProvider extends OpenAILike
{
    /** 實際模型存進 metadata 用的 key。 */
    public const META_MODEL = 'openrouter_model';

    protected function processChatResult(array $result): AssistantMessage
    {
        $message = parent::processChatResult($result);

        $model = $result['model'] ?? null;

        if (is_string($model) && $model !== '') {
            $message->addMetadata(self::META_MODEL, $model);
        }

        return $message;
    }

    /**
     * 串流時把 `delta.reasoning` 轉成 ReasoningChunk。
     *
     * **為什麼要覆寫**：NeuronAI 的 OpenAI 版 `processContentDelta()` 只讀
     * `delta.content`，而 OpenRouter 把會思考的模型的推理內容放在另一個欄位
     * `delta.reasoning`（較新的模型另外給結構化的 `delta.reasoning_details`）。
     * 不覆寫的話推理內容在這一層就被丟掉了，再往下做什麼都沒用。
     *
     * 推理內容**不寫進 content block**（不呼叫 `updateContentBlock()`）：那是在組
     * 最終的 AssistantMessage，推理進去就會變成回答的一部分，落庫與送回模型的
     * 歷史都會被污染。它只以事件的形式流出去，由呼叫端決定要不要用。
     *
     * 兩個欄位擇一：`reasoning_details` 是 `reasoning` 的結構化版本，同一段內容
     * 兩邊都會出現，兩個都收就會重複一次。
     */
    protected function processContentDelta(array $choice): Generator
    {
        $reasoning = $choice['delta']['reasoning'] ?? null;

        if (is_string($reasoning) && $reasoning !== '') {
            yield new ReasoningChunk($this->streamState->messageId(), $reasoning);
        } elseif (is_array($choice['delta']['reasoning_details'] ?? null)) {
            foreach ($choice['delta']['reasoning_details'] as $detail) {
                $text = is_array($detail) ? ($detail['text'] ?? null) : null;

                if (is_string($text) && $text !== '') {
                    yield new ReasoningChunk($this->streamState->messageId(), $text);
                }
            }
        }

        yield from parent::processContentDelta($choice);
    }
}
