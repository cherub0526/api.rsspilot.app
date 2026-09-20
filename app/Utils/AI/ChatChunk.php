<?php

declare(strict_types=1);

namespace App\Utils\AI;

/**
 * 串流推論吐出來的一個片段。
 *
 * 會思考的模型在回答之前會先送一段推理內容，兩者是**分開的兩種 delta 欄位**
 * （OpenRouter 是 `delta.reasoning` 與 `delta.content`），不能串在一起——混成
 * 同一條字串的話，思考過程會被當成回答印在對話氣泡裡。
 *
 * 所以串流的元素從純字串換成這個型別。呼叫端只想要答案時就濾掉
 * `isReasoning()` 的片段（心智圖那條路就是這樣做）。
 */
final class ChatChunk
{
    public const string TYPE_TEXT = 'text';

    public const string TYPE_REASONING = 'reasoning';

    private function __construct(
        public readonly string $type,
        public readonly string $text,
    ) {
    }

    /** 回答本身。 */
    public static function text(string $text): self
    {
        return new self(self::TYPE_TEXT, $text);
    }

    /** 回答之前的推理內容。 */
    public static function reasoning(string $text): self
    {
        return new self(self::TYPE_REASONING, $text);
    }

    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    public function isReasoning(): bool
    {
        return $this->type === self::TYPE_REASONING;
    }

    /** 內容為空的片段——NeuronAI 在串流尾端會送，前端重繪它沒有意義。 */
    public function isEmpty(): bool
    {
        return $this->text === '';
    }
}
