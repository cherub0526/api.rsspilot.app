<?php

declare(strict_types=1);

namespace App\Events\Chat;

/**
 * 模型回答之前的一段推理內容。
 *
 * 與 ChatTokenEvent 分開而不是加一個 kind 欄位：SSE 那端要送的是不同 type 的
 * payload，前端也要把它放進不同的片段，兩者從頭到尾沒有共用的處理。
 */
class ChatReasoningEvent
{
    public function __construct(
        public readonly string $token,
        public readonly string $userId,
        public readonly string $mediaId,
    ) {
    }
}
