<?php

declare(strict_types=1);

namespace App\Utils\AI;

use App\Models\ChatMessage;

/**
 * 串流推論吐出來的一個片段。
 *
 * 一輪回應裡混著四種東西：推理、工具呼叫、工具結果、回答。它們在上游是不同的
 * 欄位或事件（OpenRouter 的 `delta.reasoning` vs `delta.content`、NeuronAI 的
 * ToolCallChunk vs TextChunk），**不能串成同一條字串**——混在一起的話，思考過程
 * 與搜尋結果會被當成回答印在對話氣泡裡。
 *
 * 所以串流的元素從純字串換成這個型別。只想要答案的呼叫端就濾掉非 `isText()`
 * 的片段（心智圖那條路就是這樣做）。
 *
 * `data` 是工具片段的結構化內容，欄位與 `chat_messages.parts` 裡對應的片段
 * 一模一樣——這條管線從頭到尾不重新整形，少一個對不上的地方。
 */
final class ChatChunk
{
    public const string TYPE_TEXT = 'text';

    public const string TYPE_REASONING = 'reasoning';

    public const string TYPE_TOOL_CALL = 'tool_call';

    public const string TYPE_TOOL_RESULT = 'tool_result';

    /**
     * @param array<string, mixed> $data 工具片段的結構化內容，文字片段一律是空陣列
     */
    private function __construct(
        public readonly string $type,
        public readonly string $text,
        public readonly array $data = [],
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

    /**
     * 模型決定呼叫某個工具。
     *
     * @param array<string, mixed> $input 呼叫參數，例如搜尋工具的 search_query
     */
    public static function toolCall(string $id, string $name, array $input): self
    {
        return new self(self::TYPE_TOOL_CALL, '', [
            'id'    => $id,
            'name'  => $name,
            'input' => $input,
        ]);
    }

    /** 工具跑完的結果。 */
    public static function toolResult(string $id, string $output, bool $isError = false): self
    {
        return new self(self::TYPE_TOOL_RESULT, '', [
            'tool_call_id' => $id,
            'output'       => $output,
            'is_error'     => $isError,
        ]);
    }

    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    public function isReasoning(): bool
    {
        return $this->type === self::TYPE_REASONING;
    }

    /**
     * 內容為空的片段——NeuronAI 在串流尾端會送，前端重繪它沒有意義。
     *
     * 工具片段的內容在 `data` 而不是 `text`，所以兩邊都要看：只看 text 的話，
     * 一次搜尋會被當成空片段整個丟掉。
     */
    public function isEmpty(): bool
    {
        return $this->text === '' && $this->data === [];
    }

    /**
     * 落庫用的形狀，對應 chat_messages.parts 的一個片段。
     *
     * **型別名稱要換一次**：串流這一側沿用上游的詞彙（OpenRouter 的
     * `delta.reasoning`），儲存與前端那一側用的是 `thinking`（ChatMessage 的
     * PART_* 常數）。兩邊各自都已經有人用，硬要統一得動資料；照抄不換的話會寫進
     * 一個前端不認得的片段型別，畫面上就是整段思考消失。
     *
     * @return array<string, mixed>
     */
    public function toPart(): array
    {
        $type = $this->type === self::TYPE_REASONING ? ChatMessage::PART_THINKING : $this->type;

        return $this->data === []
            ? ['type' => $type, 'text' => $this->text]
            : ['type' => $type, ...$this->data];
    }
}
