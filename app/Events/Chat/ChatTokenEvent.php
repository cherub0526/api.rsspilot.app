<?php

declare(strict_types=1);

namespace App\Events\Chat;

class ChatTokenEvent
{
    public function __construct(
        public readonly string $token,
        public readonly string $userId,
        public readonly string $mediaId,
    ) {}
}
