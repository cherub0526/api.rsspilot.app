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
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;

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
    public function stream(
        string $instructions,
        array $messages,
        ?User $user = null,
        ?string $sessionId = null,
        bool $withReasoning = false,
        bool $withWebSearch = false
    ): Generator {
        yield from RoutedInference::stream(
            self::class,
            $user,
            fn (RoutingProfile $profile): Generator => $this->streamWith(
                $profile,
                $instructions,
                $messages,
                $sessionId,
                $withReasoning,
                $withWebSearch
            )
        );
    }

    /**
     * @param array<int, array{role: string, content: string, images?: array<int, string>}> $messages
     * @return Generator<int, ChatChunk>
     */
    private function streamWith(
        RoutingProfile $profile,
        string $instructions,
        array $messages,
        ?string $sessionId = null,
        bool $withReasoning = false,
        bool $withWebSearch = false
    ): Generator {
        $agent = Agent::make()
            ->setAiProvider(new OpenRouterProvider(
                baseUri: (string) config('ai.openrouter.base_uri'),
                key: (string) config('ai.openrouter.api_key'),
                model: $profile->model,
                parameters: $this->parametersFor($profile, $sessionId, $withReasoning),
            ))
            ->setInstructions($instructions);

        if ($withWebSearch && ($searchTool = $this->webSearchTool()) !== null) {
            $agent->addTool($searchTool);
        }

        // stream() 回傳 AgentHandler，events() 才是實際逐段產生的 generator。
        // 事件流裡還有 inference-start 一類的生命週期事件，不是這四種就忽略。
        foreach ($agent->stream($this->toMessages($messages))->events() as $event) {
            if ($event instanceof TextChunk) {
                yield ChatChunk::text($event->content);
            } elseif ($event instanceof ReasoningChunk) {
                yield ChatChunk::reasoning($event->content);
            } elseif ($event instanceof ToolCallChunk) {
                yield ChatChunk::toolCall(
                    (string) $event->tool->getCallId(),
                    $event->tool->getName(),
                    $event->tool->getInputs()
                );
            } elseif ($event instanceof ToolResultChunk) {
                yield ChatChunk::toolResult(
                    (string) $event->tool->getCallId(),
                    $event->tool->getResult()
                );
            }
        }
    }

    /**
     * 上網查資料的工具，沒有設定 key 時回 null。
     *
     * 開放給測試呼叫，理由同 parametersFor()：工具實際有沒有被掛上去，只有在這
     * 個層級斷言得到。
     *
     * `withOptions()` 是**整組覆蓋**而不是合併，所以預設值要自己寫齊。其中
     * `include_answer` 不能省：TavilySearchTool 的 `__invoke()` 直接讀
     * `$result['answer']`，Tavily 沒被要求產生摘要時不會有這個鍵，每次搜尋都會
     * 炸在那一行。
     */
    public function webSearchTool(): ?TavilySearchTool
    {
        $key = trim((string) config('ai.chat.web_search.tavily_key'));

        if ($key === '') {
            return null;
        }

        // 不把 setMaxRuns() 串在後面：它宣告在父類別 Tool 上、回傳型別是 Tool，
        // 接著再呼叫 withOptions() 靜態分析就過不了。
        $tool = TavilySearchTool::make($key)->withOptions([
            'search_depth'      => 'basic',
            'chunks_per_source' => 3,
            'max_results'       => (int) config('ai.chat.web_search.max_results'),
            'include_answer'    => true,
        ]);

        $tool->setMaxRuns((int) config('ai.chat.web_search.max_runs'));

        return $tool;
    }

    /**
     * 路由參數加上 OpenRouter 的 `session_id`。
     *
     * `session_id` 是 OpenRouter 的 sticky routing key：同一個 id 的請求會被送到
     * 同一家 provider，讓對方的 prompt cache 有機會命中。對話每一輪都要重送整份
     * 摘要與歷史，這個開關省下的是那一大段重複的 input token；它**不會**讓我們
     * 少送 messages，OpenRouter 沒有替我們保存對話。
     *
     * 擺在後面蓋過路由參數：設定檔裡若真的寫了 session_id，那也只是個固定值，
     * 而這裡拿到的是這次對話真正的識別碼。
     *
     * 退回用途層重試時沿用同一個 id——重試仍屬於同一段對話，換 id 只會讓那一輪
     * 落到另一家 provider。
     *
     * 開放給測試呼叫：真正送出去的 body 攔不到（NeuronAI 自己建 Guzzle client），
     * 把參數的組法留在一個可以直接斷言的方法上，是這裡唯一測得到的層級。
     *
     * @return array<string, mixed>
     */
    public function parametersFor(
        RoutingProfile $profile,
        ?string $sessionId,
        bool $withReasoning = false
    ): array {
        $parameters = $profile->parameters;

        if ($sessionId !== null && $sessionId !== '') {
            $parameters['session_id'] = $sessionId;
        }

        // 方案自己指定了 reasoning 就用它的（`plans.ai_routing`，見 Plan::aiRouting()）。
        // 思考力度跟 cost_tier、max_price 一樣是**每個方案的成本設定**，所以旋鈕放在
        // 同一個地方、同樣改資料不必部署；這裡的 config 只是沒指定時的預設值。
        if ($withReasoning && !isset($parameters['reasoning'])) {
            if (($effort = $this->reasoningEffort()) !== null) {
                // exclude 明寫 false：要的就是「把推理內容一起串出來」，而不是只讓
                // 模型多想一輪。不支援思考的模型 OpenRouter 會直接忽略這個參數。
                $parameters['reasoning'] = ['effort' => $effort, 'exclude' => false];
            }
        }

        return $parameters;
    }

    /**
     * 這次要請模型想多用力，關閉時回 null。
     *
     * 只認 OpenRouter 收的三個值——設定寫錯就等同關閉，不要把一個會被上游拒絕
     * 的字串送出去換一個 400。
     */
    private function reasoningEffort(): ?string
    {
        $effort = strtolower(trim((string) config('ai.chat.reasoning_effort')));

        return in_array($effort, ['low', 'medium', 'high'], true) ? $effort : null;
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
