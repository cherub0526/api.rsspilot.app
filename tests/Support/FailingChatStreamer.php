<?php

declare(strict_types=1);

namespace Tests\Support;

use Generator;
use RuntimeException;
use App\Utils\AI\ChatStreamerInterface;

/**
 * 先吐出幾個片段，然後在串流途中炸掉。
 *
 * 用來區分「一個 token 都沒拿到」與「已經串出部分內容」——這兩種失敗在
 * 每日額度上的處理不同（前者退還、後者算用掉）。
 */
class FailingChatStreamer implements ChatStreamerInterface
{
    public ?string $instructions = null;

    /** @var array<int, array{role: string, content: string}> */
    public array $messages = [];

    /** @param string[] $tokens 炸掉之前先送出的片段，空陣列代表一個都沒送出 */
    public function __construct(private array $tokens = [])
    {
    }

    public function stream(string $instructions, array $messages): Generator
    {
        $this->instructions = $instructions;
        $this->messages = $messages;

        yield from $this->tokens;

        throw new RuntimeException('upstream exploded');
    }
}
