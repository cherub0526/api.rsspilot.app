<?php

declare(strict_types=1);

namespace App\Utils\AI;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

/**
 * 以 NeuronAI 對 OpenRouter 做串流推論。
 *
 * 用 OpenAILike 而不是 OpenAI：NeuronAI 沒有內建 OpenRouter provider，而 OpenRouter
 * 是 OpenAI 相容 API，OpenAILike 就是為此提供的、可指定 baseUri 的子類。
 *
 * NeuronAI 預設使用自己的 GuzzleHttpClient（底層 ext-curl）。本專案的 Swoole 開了
 * SWOOLE_HOOK_NATIVE_CURL，所以請求會走協程 hook、不會阻塞 worker。務必不要換成
 * AmpHttpClient —— 它自帶 event loop，會和 Swoole 打架。
 */
class NeuronChatStreamer implements ChatStreamerInterface
{
    public function stream(string $instructions, array $messages): Generator
    {
        $agent = Agent::make()
            ->setAiProvider(new OpenAILike(
                baseUri: (string) config('ai.openrouter.base_uri'),
                key: (string) config('ai.openrouter.api_key'),
                model: OpenRouterModels::for(self::class),
            ))
            ->setInstructions($instructions);

        // stream() 回傳 AgentHandler，events() 才是實際逐段產生的 generator。
        // 事件流裡除了文字還有推理、工具呼叫等 chunk，這裡只取文字。
        foreach ($agent->stream($this->toMessages($messages))->events() as $event) {
            if ($event instanceof TextChunk) {
                yield $event->content;
            }
        }
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return Message[]
     */
    private function toMessages(array $messages): array
    {
        return array_map(
            fn (array $message): Message => new Message(
                MessageRole::tryFrom($message['role']) ?? MessageRole::USER,
                $message['content']
            ),
            $messages
        );
    }
}
