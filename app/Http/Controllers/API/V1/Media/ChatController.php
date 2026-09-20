<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Throwable;
use Hypervel\Http\Request;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Utils\AI\ChatChunk;
use OpenApi\Attributes as OAT;
use App\Validators\ChatValidator;
use App\Events\Chat\ChatDoneEvent;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Responses\Http429;
use App\Services\ChatQuotaService;
use App\Services\ThumbnailService;
use App\Events\Chat\ChatErrorEvent;
use App\Events\Chat\ChatTokenEvent;
use Hypervel\Support\Facades\Event;
use App\Services\DailyQuotaSnapshot;
use App\Events\Chat\ChatToolCallEvent;
use App\Events\Chat\ChatReasoningEvent;
use App\Utils\AI\ChatStreamerInterface;
use Psr\Http\Message\ResponseInterface;
use App\Events\Chat\ChatToolResultEvent;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Services\Prompts\TemplateFactory;
use App\Exceptions\InvalidRequestException;
use App\Exceptions\ChatQuotaExceededException;
use App\Http\Controllers\Concerns\ResolvesUserPlan;
use App\Http\Controllers\API\V1\Media\Chat\ResolvesMedia;

class ChatController
{
    use ResolvesMedia;
    use ResolvesUserPlan;

    /**
     * 帶圖提問要扣幾點每日額度。
     *
     * vision 推論的單次成本明顯高於純文字，扣一樣的點數等於讓帶圖的人用同樣的
     * 額度買到更貴的東西。純文字仍是 1 點。
     *
     * 剩餘不足時整個請求被擋下來、不做部分扣點——所以剩 1 點的使用者附了圖就會
     * 拿到 429，即使畫面上顯示「還有 1 次」。前端因此在附圖時會標明這則要扣 2 點。
     */
    public const int QUOTA_COST_WITH_IMAGES = 2;

    /**
     * 一則使用者訊息最多帶幾張截圖，見 ChatValidator。
     */
    private const int IMAGES_PER_MESSAGE = 4;

    /**
     * 整個請求（含歷史）送進推論的截圖總數上限。
     *
     * 前端會把完整歷史送回來，裡頭每一則提問都可能帶著當時的截圖；照單全收的話
     * 對話愈長、每一輪要重付的圖片 token 就愈多。取最新的幾張是折衷：「剛剛那張
     * 圖的旁邊那欄呢」這種接續追問仍然成立，更早的截圖則只留下它們當時的文字。
     */
    private const int IMAGES_PER_REQUEST = 4;

    public function __construct(
        private ChatStreamerInterface $streamer,
        private ChatQuotaService $quota,
        private ThumbnailService $thumbnails,
    ) {
    }

    /**
     * POST /v1/media/{mediaId}/chat.
     *
     * 接收使用者訊息，向 OpenRouter 發送串流請求。
     * 每個 token 透過 ChatTokenEvent 廣播給對應的 SSE 長連線；會思考的模型在回答
     * 之前先送出的推理內容走 ChatReasoningEvent，兩者在前端是不同的片段。
     * 回傳時機：完整回應產生後（或發生錯誤時）。
     *
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
     * @throws ChatQuotaExceededException 當日提問額度已用盡
     */
    #[OAT\Post(
        path: '/v1/media/{mediaId}/chat',
        operationId: 'api.v1.media.chat.store',
        summary: 'Send message and broadcast AI tokens via SSE',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['messages'],
                properties: [
                    new OAT\Property(
                        property: 'session_id',
                        type: 'string',
                        nullable: true,
                        description: 'Existing session ID to continue. If omitted, a new session is created.',
                        example: '01jsvgt3prpypqwex4wj78bznk'
                    ),
                    new OAT\Property(
                        property: 'messages',
                        description: 'Only the LAST item is used — it is the question being asked. '
                            . 'Earlier items are ignored: the conversation history is rebuilt server-side '
                            . 'from the session, so it cannot be forged or padded by the client. '
                            . 'Send just the new question and the session_id it belongs to.',
                        type: 'array',
                        items: new OAT\Items(
                            required: ['role', 'content'],
                            properties: [
                                new OAT\Property(
                                    property: 'role',
                                    type: 'string',
                                    enum: ['user', 'assistant', 'system'],
                                    example: 'user'
                                ),
                                new OAT\Property(
                                    property: 'content',
                                    type: 'string',
                                    example: 'What is this video about?'
                                ),
                                new OAT\Property(
                                    property: 'images',
                                    description: 'Player screenshots attached to this turn, each identified by '
                                        . 'its second and the SHA-256 of its bytes (a second holds many frames). '
                                        . 'Upload them via POST /v1/media/{mediaId}/thumbnails first. '
                                        . 'At most 4 per message, and the 4 most recent across the whole '
                                        . 'conversation (this question plus the stored history) are the ones '
                                        . 'actually sent to the model. Only screenshots attached to THIS question '
                                        . 'make it cost 2 quota units; ones inherited from earlier turns do not.',
                                    type: 'array',
                                    items: new OAT\Items(
                                        required: ['second', 'checksum'],
                                        properties: [
                                            new OAT\Property(property: 'second', type: 'integer', example: 125),
                                            new OAT\Property(
                                                property: 'checksum',
                                                type: 'string',
                                                example: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
                                            ),
                                        ],
                                        type: 'object'
                                    ),
                                    nullable: true
                                ),
                            ]
                        ),
                        minItems: 1
                    ),
                ]
            )
        ),
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'AI response dispatched via SSE events',
                headers: [
                    new OAT\Header(
                        header: 'X-RateLimit-Limit',
                        description: 'Daily question limit of the current plan. A question carrying screenshots '
                            . 'costs 2 instead of 1. Absent when the plan is unlimited.',
                        schema: new OAT\Schema(type: 'integer', example: 3)
                    ),
                    new OAT\Header(
                        header: 'X-RateLimit-Remaining',
                        description: 'Questions left today. Absent when the plan is unlimited.',
                        schema: new OAT\Schema(type: 'integer', example: 2)
                    ),
                    new OAT\Header(
                        header: 'X-RateLimit-Reset',
                        description: 'Unix timestamp of the next quota reset. Absent when the plan is unlimited.',
                        schema: new OAT\Schema(type: 'integer', example: 1786752000)
                    ),
                ],
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'status', type: 'string', example: 'done'),
                        new OAT\Property(property: 'session_id', type: 'string', example: '01jsvgt3prpypqwex4wj78bznk'),
                    ]
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
            new OAT\Response(ref: Http429::class, response: 429),
        ]
    )]
    public function store(Request $request, string $mediaId): ResponseInterface
    {
        $params = $request->only(['session_id', 'messages']);

        $v = new ChatValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $media = $this->resolveMedia($request, $mediaId);
        $userId = (string) $request->user()->getKey();
        $mediaKey = (string) $media->getKey();

        // 客戶端送來的陣列只取最後一則——那是這次的提問。其餘的一律忽略，歷史
        // 改由 server 依 session 重建（見 historyOf()）。
        $lastMessage = collect($params['messages'])->last() ?? [];
        $userMessage = $lastMessage['content'] ?? '';

        // 有歷史可讀的前提是 session 已經存在，所以這裡只「找」不「建」：沒帶
        // session_id 就是這段對話的第一句，歷史是空的。建立新 session 仍然留在
        // 扣額度之後，被擋下來的請求才不會留下一堆只有提問、沒有回應的空 session。
        $session = isset($params['session_id'])
            ? $this->findSession($userId, $mediaId, (string) $params['session_id'])
            : null;

        $history = $session instanceof ChatSession
            ? $this->historyOf((string) $session->getKey())
            : [];

        // 權限與扣點看的是「這一則附了圖沒有」，不是整包 payload 裡有沒有圖。歷史
        // 中的截圖仍然會被送進推論（見 collectImages()），但那是前面幾輪已經扣過
        // 點的東西——純文字追問不該因為稍早附過圖就變成 2 點。
        $currentRefs = $this->refsIn($lastMessage);

        // 帶圖提問是 Pro 以上的功能。真正的成本在這裡而不是上傳——vision 推論的
        // 單次成本明顯高於純文字。
        if ($currentRefs !== []) {
            $this->assertScreenshotEnabled($request);
        }

        // 截圖在扣額度之前就驗完：指到不存在的圖是請求本身有問題，不該先扣一次
        // 額度再退還。imageUrls 的 key 是 "秒數:checksum"，值是當下簽出的限時 URL。
        $imageRefs = $this->resolveImages(
            $mediaKey,
            $this->collectImages([...$history, $lastMessage]),
            $currentRefs
        );
        $imageUrls = [];

        foreach ($imageRefs as $ref) {
            $imageUrls[$this->imageKey($ref)] = $this->thumbnails->url(
                $mediaKey,
                $ref['second'],
                $ref['checksum']
            );
        }

        // 額度在建立 session 之前就扣，被擋下來的請求才不會留下一堆
        // 只有提問、沒有回應的空 session。
        $quota = $this->quota->consume(
            $request->user(),
            $currentRefs === [] ? 1 : self::QUOTA_COST_WITH_IMAGES
        );

        $currentImages = $this->allowedImagesOf($lastMessage, $imageRefs);
        $session ??= $this->createSession($userId, $mediaId, $userMessage);

        $this->saveMessage(
            (string) $session->getKey(),
            ChatMessage::ROLE_USER,
            $userMessage,
            $currentImages
        );
        $buffer = '';

        // AI 回覆的片段，依抵達順序累積：推理、工具呼叫、工具結果、回答都可能
        // 交錯出現，落庫時要保持當下畫面上的順序，重播歷史才對得起來。
        $parts = [];
        $saved = false;

        // 參考資料與 /summaries 端點取同一份摘要（使用者自己的 > 同語系共用的 >
        // 第一筆共用的），否則使用者讀到的摘要跟 AI 依據的會是不同版本。
        // `true` 是只取已完成的：重跑摘要時會先建一筆 status=created、text 還是空的
        // 資料列，沒過濾就會拿到空殼。沒有可用摘要時給空字串，不退回逐字稿。
        $summaryText = $media->summaryFor($request->user(), true)?->text ?? [];
        $template = TemplateFactory::create('assistant', [
            'user_prompt'      => $summaryText['long_summary']['content'] ?? '',
            'respond_language' => $request->user()->aiLanguageName(),
        ]);

        try {
            // 帶使用者進去：對話是 per-user 的產物，吃這個人方案的路由設定。
            // 帶 session 進去：讓同一段對話的每一輪黏在同一家 provider，重送的
            // 摘要與歷史才有機會命中對方的 prompt cache。
            // 對話是唯一會把思考過程顯示出來的路徑，所以只有這裡開 withReasoning。
            // 上網查資料另外看方案：plans.agent_enabled（目前只有 Advance）。
            $stream = $this->streamer->stream(
                $template->getSystemPrompt(),
                $this->buildMessages($history, $userMessage, $currentImages, $imageUrls),
                $request->user(),
                (string) $session->getKey(),
                true,
                $this->webSearchEnabled($request)
            );

            foreach ($stream as $chunk) {
                // NeuronAI 在串流尾端會送出內容為空的 chunk。串接結果不受影響，
                // 但每一則都會變成一次事件，讓 SSE 前端做無意義的重繪。
                if ($chunk->isEmpty()) {
                    continue;
                }

                $parts[] = $chunk->toPart();

                if ($chunk->isText()) {
                    $buffer .= $chunk->text;
                }

                Event::dispatch($this->chunkEvent($chunk, $userId, $mediaId));
            }

            $this->saveAiMessage((string) $session->getKey(), $parts, $buffer);
            $saved = true;
            Event::dispatch(new ChatDoneEvent($userId, $mediaId));
        } catch (Throwable $e) {
            if (!$saved) {
                $this->saveAiMessage((string) $session->getKey(), $parts, $buffer);
            }

            // 一個 token 都沒拿到才退還額度。已經串出部分內容的話使用者實際看過
            // 回應、上游 token 也已經花掉了，那次算用掉。
            //
            // 判準看的是**回答**，不是推理也不是搜尋：只吐了思考過程、或只搜了一輪
            // 就斷掉的話，使用者拿到的是一段沒有結論的獨白，那一次該退。上游確實
            // 已經收了推理與搜尋的錢，但那是我們選擇開這些能力的代價，不該轉嫁到
            // 他的每日額度上。
            if ($buffer === '') {
                $this->quota->release($request->user(), $quota);
            }

            Event::dispatch(new ChatErrorEvent($e->getMessage(), $userId, $mediaId));
            throw $e;
        }

        return $this->withQuotaHeaders(
            response()->json(['status' => 'done', 'session_id' => (string) $session->getKey()]),
            $quota
        );
    }

    /**
     * 成功的回應也帶 X-RateLimit-*，前端不必等到被擋才知道今天還剩幾次。
     * 不限制的方案不會有這組 header（見 DailyQuotaSnapshot::headers()）。
     */
    private function withQuotaHeaders(ResponseInterface $response, DailyQuotaSnapshot $quota): ResponseInterface
    {
        foreach ($quota->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * 組出送去推論的訊息陣列：先前輪次接上本次提問。
     *
     * 推論層要求訊息以 user 開頭並嚴格 user / assistant 交替，違反就整個請求失敗。
     * 但 ChatValidator 並不限制客戶端送來的順序（role 只驗 in:user,assistant,system），
     * 所以這裡必須正規化，否則合法的請求會變成 500：
     *
     * 1. system 併入 user —— 系統提示詞由 instructions 帶入，這裡不另開 system 角色
     * 2. 連續同角色合併成一則，內容以空行相接
     * 3. 丟掉開頭的 assistant —— 沒有對應提問的回應，留著只會讓序列不合法
     *
     * 合併同角色時圖片跟著文字一起併，順序不變——合併後仍是同一個人連續說的話，
     * 他附的圖也該留在同一則裡。
     *
     * @param array<int, array<string, mixed>> $history
     * @param array<int, array{second: int, checksum: string}> $currentImages 本次提問附上的截圖
     * @param array<string, string> $imageUrls "秒數:checksum" => 限時 URL
     * @return array<int, array{role: string, content: string, images?: array<int, string>}>
     */
    private function buildMessages(
        array $history,
        string $userMessage,
        array $currentImages,
        array $imageUrls
    ): array {
        $allowed = $this->refsOf($imageUrls);

        // role / content 由 ChatValidator 保證必填，這裡不需再防禦性檢查。
        $messages = array_map(
            fn (array $message): array => [
                'role'    => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $message['content'],
                'images'  => $this->urlsFor($this->allowedImagesOf($message, $allowed), $imageUrls),
            ],
            $history
        );
        $messages[] = [
            'role'    => 'user',
            'content' => $userMessage,
            'images'  => $this->urlsFor($currentImages, $imageUrls),
        ];

        $normalised = [];

        foreach ($messages as $message) {
            if ($normalised === [] && $message['role'] !== 'user') {
                continue;
            }

            $last = array_key_last($normalised);

            if ($last !== null && $normalised[$last]['role'] === $message['role']) {
                $normalised[$last]['content'] .= "\n\n" . $message['content'];
                $normalised[$last]['images'] = array_merge(
                    $normalised[$last]['images'],
                    $message['images']
                );
                continue;
            }

            $normalised[] = $message;
        }

        // 沒有附圖的回合不帶這個 key，推論層才能照原本的方式送純字串。
        return array_map(
            fn (array $message): array => $message['images'] === []
                ? ['role' => $message['role'], 'content' => $message['content']]
                : $message,
            $normalised
        );
    }

    /**
     * 一張截圖的身分。second 是位置、checksum 才是識別——同一秒有 24–60 幀，
     * 只用秒數當 key 會讓同一秒的不同畫面互相頂替。
     *
     * @param array{second: int, checksum: string} $ref
     */
    private function imageKey(array $ref): string
    {
        return $ref['second'] . ':' . $ref['checksum'];
    }

    /**
     * 從 imageUrls 的 key 還原成 ref 陣列。
     *
     * @param array<string, string> $imageUrls
     * @return array<int, array{second: int, checksum: string}>
     */
    private function refsOf(array $imageUrls): array
    {
        return array_map(
            function (string $key): array {
                [$second, $checksum] = explode(':', $key, 2);

                return ['second' => (int) $second, 'checksum' => $checksum];
            },
            array_keys($imageUrls)
        );
    }

    /**
     * 把一筆請求裡的截圖正規化成 ref；形狀不對就丟掉。
     *
     * @return null|array{second: int, checksum: string}
     */
    private function toRef(mixed $image): ?array
    {
        if (!is_array($image) || !isset($image['second'], $image['checksum'])) {
            return null;
        }

        return [
            'second'   => (int) $image['second'],
            'checksum' => (string) $image['checksum'],
        ];
    }

    /**
     * 從整個請求裡挑出要送進推論的截圖，由新到舊取到額度用完為止。
     *
     * 回傳的順序是「影片時間軸的先後」而不是「被挑中的先後」，同一秒再以 checksum
     * 排序讓結果可預測。這個順序只影響可讀性——真正決定每則訊息裡圖片順序的是
     * allowedImagesOf()，它保留訊息自己的順序。
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array{second: int, checksum: string}>
     */
    private function collectImages(array $messages): array
    {
        $picked = [];

        foreach (array_reverse($messages) as $message) {
            $images = $message['images'] ?? [];

            if (!is_array($images)) {
                continue;
            }

            // 同一則訊息裡也是由新到舊：額度剩一張時，該留下的是這個人最後截的
            // 那一格，而不是他最早截的那一格。
            $recentFirst = array_reverse(array_slice($images, 0, self::IMAGES_PER_MESSAGE));

            foreach ($recentFirst as $image) {
                if (count($picked) >= self::IMAGES_PER_REQUEST) {
                    break 2;
                }

                $ref = $this->toRef($image);

                if ($ref === null) {
                    continue;
                }

                $picked[$this->imageKey($ref)] = $ref;
            }
        }

        $refs = array_values($picked);
        usort(
            $refs,
            fn (array $a, array $b): int => [$a['second'], $a['checksum']]
                <=> [$b['second'], $b['checksum']]
        );

        return $refs;
    }

    /**
     * 這則訊息附的截圖裡，有被 collectImages() 選中的那些，並保留訊息自己的順序。
     *
     * @param array<string, mixed> $message
     * @param array<int, array{second: int, checksum: string}> $allowed
     * @return array<int, array{second: int, checksum: string}>
     */
    private function allowedImagesOf(array $message, array $allowed): array
    {
        $images = $message['images'] ?? [];

        if (!is_array($images)) {
            return [];
        }

        $allowedKeys = array_flip(array_map($this->imageKey(...), $allowed));
        $seen = [];
        $kept = [];

        foreach ($images as $image) {
            $ref = $this->toRef($image);

            if ($ref === null) {
                continue;
            }

            $key = $this->imageKey($ref);

            if (!isset($allowedKeys[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $ref;
        }

        return $kept;
    }

    /**
     * @param array<int, array{second: int, checksum: string}> $refs
     * @param array<string, string> $imageUrls
     * @return array<int, string>
     */
    private function urlsFor(array $refs, array $imageUrls): array
    {
        return array_values(array_filter(array_map(
            fn (array $ref): ?string => $imageUrls[$this->imageKey($ref)] ?? null,
            $refs
        )));
    }

    /**
     * 留下真的還在的截圖，並在「這次附的圖」指不到東西時擋下整個請求。
     *
     * 兩種缺圖的處理刻意不同：
     *
     * - **這次附的**——整個請求擋下來。不靜默略過是因為那會讓使用者看見自己送出的
     *   縮圖、AI 的回答卻完全沒提到畫面，而且找不出哪裡不對。附圖上傳本來就在送出
     *   之前完成，真的缺圖代表前端狀態壞了，早點講清楚比較好。
     * - **歷史裡的**——默默丟掉。歷史現在由 server 重建，使用者無從把它拿掉；讓一張
     *   早就不存在的舊截圖把整段對話永久卡死，代價遠大於少送一張圖。被丟掉的那張
     *   當時的文字仍然留在歷史裡。
     *
     * @param array<int, array{second: int, checksum: string}> $refs 整包 payload 要送的截圖
     * @param array<int, array{second: int, checksum: string}> $currentRefs 其中屬於這次提問的
     * @return array<int, array{second: int, checksum: string}>
     * @throws InvalidRequestException
     */
    private function resolveImages(string $mediaKey, array $refs, array $currentRefs): array
    {
        $currentKeys = array_flip(array_map($this->imageKey(...), $currentRefs));
        $kept = [];

        foreach ($refs as $ref) {
            if ($this->thumbnails->exists($mediaKey, $ref['second'], $ref['checksum'])) {
                $kept[] = $ref;
                continue;
            }

            if (isset($currentKeys[$this->imageKey($ref)])) {
                throw new InvalidRequestException([
                    'images' => [__('validators.controllers.thumbnails.not_found')],
                ]);
            }
        }

        return $kept;
    }

    /**
     * 一則訊息自己附的截圖，正規化並去重，上限與 ChatValidator 的 `max:4` 一致。
     *
     * 與 allowedImagesOf() 的差別是這裡不比對任何白名單——它回答的是「這則訊息
     * 附了什麼」，而不是「這則訊息附的東西裡有哪些被選中送出去」。
     *
     * @param array<string, mixed> $message
     * @return array<int, array{second: int, checksum: string}>
     */
    private function refsIn(array $message): array
    {
        $images = $message['images'] ?? [];

        if (!is_array($images)) {
            return [];
        }

        $refs = [];

        foreach (array_slice($images, 0, self::IMAGES_PER_MESSAGE) as $image) {
            $ref = $this->toRef($image);

            if ($ref === null) {
                continue;
            }

            $refs[$this->imageKey($ref)] = $ref;
        }

        return array_values($refs);
    }

    /**
     * 找出這位使用者在這支影片底下的既有 session。
     *
     * 找不到就是 404 而不是「當成新對話」：session_id 是使用者自己傳來的，指到別人
     * 的（或不存在的）對話時安靜地開一段新的，會讓前端以為自己還在原本那一段。
     *
     * 建立新 session 分開成 createSession()，因為讀歷史必須在扣額度之前、而建立
     * 必須在之後——合在一起就沒辦法同時滿足。
     *
     * @throws NotFoundHttpException
     */
    private function findSession(string $userId, string $mediaId, string $sessionId): ChatSession
    {
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', $userId)
            ->where('media_id', $mediaId)
            ->first();

        if (!$session) {
            throw new NotFoundHttpException();
        }

        return $session;
    }

    private function createSession(string $userId, string $mediaId, string $userMessage): ChatSession
    {
        return ChatSession::create([
            'user_id'  => $userId,
            'media_id' => $mediaId,
            'title'    => mb_substr($userMessage, 0, 50),
        ]);
    }

    /**
     * 這段對話至今的訊息，由 server 自己的紀錄重建。
     *
     * **不採用客戶端送來的 `messages`**，那是這支端點唯一一處把「要送給模型什麼」
     * 的決定權交出去的地方：陣列沒有長度上限，對方送多少就有多少 input token 被
     * 計費，內容也未必真的發生過。改讀 `chat_messages` 之後，歷史的長度與內容都
     * 由 server 決定，之後要加視窗上限或摘要壓縮也才有地方可加。
     *
     * 排序用 created_at 再用 id：同一輪的提問與回應常落在同一秒，ULID 是單調遞增
     * 的，拿它當 tiebreaker 才能保證 user / assistant 的先後不會顛倒。
     *
     * @return array<int, array{role: string, content: string, images: array<int, array{second: int, checksum: string}>}>
     */
    private function historyOf(string $sessionId): array
    {
        return ChatMessage::query()
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $message): array => [
                // content 是 text 片段的投影（見 ChatMessage::partsToText()），
                // 送進推論的歷史只要文字，所以直接讀它。
                'role'    => $message->role === ChatMessage::ROLE_AI ? 'assistant' : 'user',
                'content' => (string) $message->content,
                'images'  => $this->imagesOf($message),
            ])
            ->all();
    }

    /**
     * 一則已存訊息當時附上的截圖。
     *
     * 存的是 `second` 與 `checksum` 而不是 URL——URL 帶簽章會過期，重建歷史時一律
     * 現簽（見 ChatMessage 的 PART_IMAGE 註解）。
     *
     * @return array<int, array{second: int, checksum: string}>
     */
    private function imagesOf(ChatMessage $message): array
    {
        $images = [];

        foreach ($message->contentParts() as $part) {
            if (($part['type'] ?? null) !== ChatMessage::PART_IMAGE) {
                continue;
            }

            $ref = $this->toRef($part);

            if ($ref !== null) {
                $images[] = $ref;
            }
        }

        return $images;
    }

    /**
     * @param array<int, array{second: int, checksum: string}> $images
     *                                                                 這則訊息附上的截圖（只有使用者訊息會有）
     */
    private function saveMessage(
        string $sessionId,
        string $role,
        string $content,
        array $images = []
    ): void {
        if ($content === '') {
            return;
        }

        // 截圖排在文字前面，跟送進推論時的順序一致，重播歷史才不會前後顛倒。
        // 片段記秒數與 checksum：圖片在 S3 的位置由這兩者加 media_id 推導，存 URL
        // 會在簽章過期後變成一排破圖，輸出時才由 ChatMessageResource 現簽。
        $parts = array_map(
            fn (array $image): array => [
                'type'     => ChatMessage::PART_IMAGE,
                'second'   => $image['second'],
                'checksum' => $image['checksum'],
            ],
            array_values($images)
        );

        $parts[] = [
            'type' => ChatMessage::PART_TEXT,
            'text' => $content,
        ];

        ChatMessage::create([
            'session_id' => $sessionId,
            'role'       => $role,
            'content'    => $content,
            'parts'      => $parts,
            'created_at' => now(),
        ]);
    }

    /**
     * 落庫 AI 的回覆。
     *
     * `$parts` 是串流抵達的原始順序（推理 / 工具呼叫 / 工具結果 / 回答交錯），
     * 直接存下去——重播歷史時畫面才跟當下看到的一樣。
     *
     * `content` 只放 **text 片段**的串接結果：它是送回模型的歷史所讀的欄位，
     * 推理或搜尋結果混進去，下一輪模型就會把那些東西當成它自己說過的話。
     *
     * 沒有回答就整則不存，與使用者訊息的規則一致——只有一段思考或一次搜尋、
     * 沒有結論的回合，留在歷史裡對誰都沒有用。
     *
     * @param array<int, array<string, mixed>> $parts
     */
    private function saveAiMessage(string $sessionId, array $parts, string $content): void
    {
        if ($content === '') {
            return;
        }

        ChatMessage::create([
            'session_id' => $sessionId,
            'role'       => ChatMessage::ROLE_AI,
            'content'    => $content,
            'parts'      => $parts,
            'created_at' => now(),
        ]);
    }

    /**
     * 這個片段要廣播成哪一種事件。
     *
     * 四種片段在前端是四種畫面（回答氣泡、思考區塊、搜尋中的標題、來源清單），
     * 所以事件也分四種——共用一種再帶個 kind 欄位的話，SSE 那端與 store 那端都
     * 要各自再拆一次。
     */
    private function chunkEvent(ChatChunk $chunk, string $userId, string $mediaId): object
    {
        return match ($chunk->type) {
            ChatChunk::TYPE_REASONING => new ChatReasoningEvent($chunk->text, $userId, $mediaId),
            ChatChunk::TYPE_TOOL_CALL => new ChatToolCallEvent(
                (string) $chunk->data['id'],
                (string) $chunk->data['name'],
                (array) $chunk->data['input'],
                $userId,
                $mediaId
            ),
            ChatChunk::TYPE_TOOL_RESULT => new ChatToolResultEvent(
                (string) $chunk->data['tool_call_id'],
                (string) $chunk->data['output'],
                (bool) $chunk->data['is_error'],
                $userId,
                $mediaId
            ),
            default => new ChatTokenEvent($chunk->text, $userId, $mediaId),
        };
    }

    /**
     * 這位使用者能不能讓模型上網查資料。
     *
     * 判準是 plans.agent_enabled，與其他付費功能同一個做法：權益寫在資料上，
     * 不寫死方案名稱。沒有方案時一併關掉——無從判斷權益的預設是不給。
     *
     * 不拋例外而是靜默關閉：這是一個能力，不是一道閘門。沒開通的人照常對話，
     * 只是模型答不出摘要以外的東西時只能說不知道。
     */
    private function webSearchEnabled(Request $request): bool
    {
        return (bool) $this->userPlan($request)?->getAttribute('agent_enabled');
    }
}
