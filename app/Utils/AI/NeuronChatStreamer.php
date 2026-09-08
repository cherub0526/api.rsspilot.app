<?php

declare(strict_types=1);

namespace App\Utils\AI;

use Generator;
use App\Models\User;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

/**
 * 以 NeuronAI 對 OpenRouter 做串流推論。
 *
 * provider 用 OpenRouterProvider（OpenAILike 的子類）：NeuronAI 沒有內建 OpenRouter
 * provider，而 OpenRouter 是 OpenAI 相容 API，OpenAILike 就是為此提供的、可指定
 * baseUri 的子類；再往下包一層是為了帶路由參數。
 *
 * **串流拿不到實際模型。** OpenRouterProvider 的模型擷取掛在 processChatResult()，
 * 而串流走的是 HandleStream，訊息在生成器結束時才組出來、也不從 events() 流出。
 * 所以 chat 走 openrouter/auto 時，帳單以外看不出每次實際跑了哪個模型。
 * 詳見 docs/lore/prompts/pitfalls.md〈NeuronAI 會丟掉回應的 model 欄位〉。
 *
 * NeuronAI 預設使用自己的 GuzzleHttpClient（底層 ext-curl）。本專案的 Swoole 開了
 * SWOOLE_HOOK_NATIVE_CURL，所以請求會走協程 hook、不會阻塞 worker。務必不要換成
 * AmpHttpClient —— 它自帶 event loop，會和 Swoole 打架。
 */
class NeuronChatStreamer implements ChatStreamerInterface
{
    public function stream(string $instructions, array $messages, ?User $user = null): Generator
    {
        yield from RoutedInference::stream(
            self::class,
            $user,
            fn (RoutingProfile $profile): Generator => $this->streamWith($profile, $instructions, $messages)
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return Generator<int, string>
     */
    private function streamWith(RoutingProfile $profile, string $instructions, array $messages): Generator
    {
        $agent = Agent::make()
            ->setAiProvider(new OpenRouterProvider(
                baseUri: (string) config('ai.openrouter.base_uri'),
                key: (string) config('ai.openrouter.api_key'),
                model: $profile->model,
                parameters: $profile->parameters,
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
