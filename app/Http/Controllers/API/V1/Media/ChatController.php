<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Throwable;
use Hypervel\Http\Request;
use App\Models\ChatMessage;
use App\Models\ChatSession;
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
use App\Utils\AI\ChatStreamerInterface;
use Psr\Http\Message\ResponseInterface;
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
     * 每個 token 透過 ChatTokenEvent 廣播給對應的 SSE 長連線。
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
                                        . 'At most 4 per message, and the 4 most recent across the whole request '
                                        . 'are the ones actually sent to the model.',
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

        // 截圖在扣額度之前就驗完：指到不存在的圖是請求本身有問題，不該先扣一次
        // 額度再退還。imageUrls 的 key 是 "秒數:checksum"，值是當下簽出的限時 URL。
        $imageRefs = $this->collectImages($params['messages']);

        // 帶圖提問是 Pro 以上的功能。真正的成本在這裡而不是上傳——vision 推論的
        // 單次成本明顯高於純文字，而每日 chat 額度沒有為帶圖加權。純文字提問
        // 不受影響，所以只在真的帶了圖時才檢查。
        if ($imageRefs !== []) {
            $this->assertScreenshotEnabled($request);
        }

        $this->assertThumbnailsExist($mediaKey, $imageRefs);
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
            $imageRefs === [] ? 1 : self::QUOTA_COST_WITH_IMAGES
        );

        $lastMessage = collect($params['messages'])->last() ?? [];
        $userMessage = $lastMessage['content'] ?? '';
        $currentImages = $this->allowedImagesOf($lastMessage, $imageRefs);
        $session = $this->findOrCreateSession(
            $userId,
            $mediaId,
            $params['session_id'] ?? null,
            $userMessage
        );
        $this->saveMessage(
            (string) $session->getKey(),
            ChatMessage::ROLE_USER,
            $userMessage,
            $currentImages
        );
        $buffer = '';
        $saved = false;

        // 最後一句稍後單獨接在訊息陣列結尾，這裡去掉以免重複。
        $history = $params['messages'];
        array_pop($history);

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
            $stream = $this->streamer->stream(
                $template->getSystemPrompt(),
                $this->buildMessages($history, $userMessage, $currentImages, $imageUrls),
                $request->user()
            );

            foreach ($stream as $token) {
                // NeuronAI 在串流尾端會送出內容為空的 chunk。串接結果不受影響，
                // 但每一則都會變成一次 ChatTokenEvent，讓 SSE 前端做無意義的重繪。
                if ($token === '') {
                    continue;
                }

                $buffer .= $token;
                Event::dispatch(new ChatTokenEvent($token, $userId, $mediaId));
            }

            $this->saveMessage((string) $session->getKey(), ChatMessage::ROLE_AI, $buffer);
            $saved = true;
            Event::dispatch(new ChatDoneEvent($userId, $mediaId));
        } catch (Throwable $e) {
            if (!$saved) {
                $this->saveMessage((string) $session->getKey(), ChatMessage::ROLE_AI, $buffer);
            }

            // 一個 token 都沒拿到才退還額度。已經串出部分內容的話使用者實際看過
            // 回應、上游 token 也已經花掉了，那次算用掉。
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
     * 指到不存在的截圖就整個請求擋下來。
     *
     * 不改成「靜默略過」是因為那會讓使用者看見自己送出的縮圖、AI 的回答卻完全
     * 沒提到畫面，而且找不出哪裡不對。附圖上傳本來就在送出之前完成，真的缺圖
     * 代表前端狀態壞了，早點講清楚比較好。
     *
     * @param array<int, array{second: int, checksum: string}> $refs
     * @throws InvalidRequestException
     */
    private function assertThumbnailsExist(string $mediaKey, array $refs): void
    {
        foreach ($refs as $ref) {
            if (!$this->thumbnails->exists($mediaKey, $ref['second'], $ref['checksum'])) {
                throw new InvalidRequestException([
                    'images' => [__('validators.controllers.thumbnails.not_found')],
                ]);
            }
        }
    }

    /**
     * 找到或建立 ChatSession。
     * session_id 有傳 → 驗證所有權；未傳 → 自動建立。
     *
     * @throws NotFoundHttpException
     */
    private function findOrCreateSession(string $userId, string $mediaId, ?string $sessionId, string $userMessage): ChatSession
    {
        if ($sessionId !== null) {
            $session = ChatSession::where('id', $sessionId)
                ->where('user_id', $userId)
                ->where('media_id', $mediaId)
                ->first();

            if (!$session) {
                throw new NotFoundHttpException();
            }

            return $session;
        }

        $title = mb_substr($userMessage, 0, 50);

        return ChatSession::create([
            'user_id'  => $userId,
            'media_id' => $mediaId,
            'title'    => $title,
        ]);
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

        // AI 的回覆目前只有純文字，所以片段就是單一 text。thinking 與 tool_call
        // 要等 agent 能力接上來才會出現在這個陣列裡，屆時 content 仍是文字投影。
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
}
