<?php

declare(strict_types=1);

namespace App\Utils\AI;

use Generator;
use App\Models\User;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;

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
     * @param array<int, array{role: string, content: string, images?: array<int, string>}> $messages
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
     * @param array<int, array{role: string, content: string, images?: array<int, string>}> $messages
     * @return Message[]
     */
    private function toMessages(array $messages): array
    {
        return array_map(
            fn (array $message): Message => new Message(
                MessageRole::tryFrom($message['role']) ?? MessageRole::USER,
                $this->toContent($message)
            ),
            $messages
        );
    }

    /**
     * 沒有附圖時維持傳字串。
     *
     * NeuronAI 對字串與 content block 陣列的處理並不等價：字串會被包成單一
     * TextContent，而陣列會原樣映射成 OpenAI 的 content parts 形狀。純文字回合
     * 走陣列只是讓每一次請求的 payload 多一層結構，沒有好處。
     *
     * 圖片以 URL 交給上游自行抓取（SourceType::URL → `image_url`），不轉 base64
     * ——同一張圖在多輪對話裡會被重複帶上，內嵌等於每一輪都把它整個重傳一次。
     *
     * @param array{role: string, content: string, images?: array<int, string>} $message
     * @return array<int, ImageContent|TextContent>|string
     */
    private function toContent(array $message): array|string
    {
        $images = $message['images'] ?? [];

        if ($images === []) {
            return $message['content'];
        }

        // 圖片排在文字前面：提問幾乎都是在指涉圖片（「這一格在講什麼」），
        // 先給畫面再給問題，指涉對象才在問題出現之前就已經在脈絡裡。
        $blocks = array_map(
            fn (string $url): ImageContent => new ImageContent($url, SourceType::URL),
            array_values($images)
        );
        $blocks[] = new TextContent($message['content']);

        return $blocks;
    }
}
