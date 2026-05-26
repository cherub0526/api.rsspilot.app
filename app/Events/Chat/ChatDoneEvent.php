<?php

declare(strict_types=1);

namespace App\Events\Chat;

class ChatDoneEvent
{
    public function __construct(
        public readonly string $userId,
        public readonly string $mediaId,
    ) {}
}
