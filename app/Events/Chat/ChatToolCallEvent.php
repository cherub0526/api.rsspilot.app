<?php

declare(strict_types=1);

namespace App\Events\Chat;

/**
 * 模型決定呼叫某個工具（目前只有上網查資料）。
 *
 * 在工具真的跑之前就發：搜尋要花好幾秒，使用者該在那之前就看到「正在搜尋什麼」，
 * 而不是盯著一個沒有動靜的畫面等結果。
 */
class ChatToolCallEvent
{
    /**
     * @param array<string, mixed> $input 呼叫參數，例如搜尋工具的 search_query
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
        public readonly string $userId,
        public readonly string $mediaId,
    ) {
    }
}
