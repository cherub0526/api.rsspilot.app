<?php

declare(strict_types=1);

namespace App\Events\Chat;

class ChatErrorEvent
{
    public function __construct(
        public readonly string $message,
        public readonly string $userId,
        public readonly string $mediaId,
    ) {}
}
