<?php

declare(strict_types=1);

namespace Tests\Support;

use Generator;
use App\Utils\AI\ChatStreamerInterface;

/**
 * 取代真實推論的測試替身。
 *
 * NeuronAI 走自己建立的 Guzzle client，Hypervel 的 Http::fake() 攔不到，
 * 所以改成在容器綁定這個實作，並把收到的參數留下來供斷言。
 */
class FakeChatStreamer implements ChatStreamerInterface
{
    public ?string $instructions = null;

    /** @var array<int, array{role: string, content: string}> */
    public array $messages = [];

    public int $calls = 0;

    /** @param string[] $tokens 依序產生的回應片段 */
    public function __construct(private array $tokens = ['Hello'])
    {
    }

    public function stream(string $instructions, array $messages): Generator
    {
        ++$this->calls;
        $this->instructions = $instructions;
        $this->messages = $messages;

        yield from $this->tokens;
    }

    /** 送出的訊息內容，依序排列。 */
    public function contents(): array
    {
        return array_column($this->messages, 'content');
    }
}
