<?php

declare(strict_types=1);

namespace App\Events\Chat;

/** 工具跑完的結果。 */
class ChatToolResultEvent
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $output,
        public readonly bool $isError,
        public readonly string $userId,
        public readonly string $mediaId,
    ) {
    }
}
