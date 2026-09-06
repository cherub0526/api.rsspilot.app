<?php

declare(strict_types=1);

namespace App\Utils\AI;

use NeuronAI\Providers\OpenAILike;
use NeuronAI\Chat\Messages\AssistantMessage;

/**
 * OpenAILike 再加一件事：把 OpenRouter 回報的**實際模型**留在訊息的 metadata 上。
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
}
