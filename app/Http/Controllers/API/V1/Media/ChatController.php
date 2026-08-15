<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Throwable;
use App\Models\Summary;
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
use App\Events\Chat\ChatErrorEvent;
use App\Events\Chat\ChatTokenEvent;
use App\Services\ChatQuotaSnapshot;
use Hypervel\Support\Facades\Event;
use App\Utils\AI\ChatStreamerInterface;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Services\Prompts\TemplateFactory;
use App\Exceptions\InvalidRequestException;
use App\Exceptions\ChatQuotaExceededException;
use App\Http\Controllers\API\V1\Media\Chat\ResolvesMedia;

class ChatController
{
    use ResolvesMedia;

    public function __construct(
        private ChatStreamerInterface $streamer,
        private ChatQuotaService $quota,
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
                        description: 'Daily question limit of the current plan. Absent when the plan is unlimited.',
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

        // 額度在建立 session 之前就扣，被擋下來的請求才不會留下一堆
        // 只有提問、沒有回應的空 session。
        $quota = $this->quota->consume($request->user());

        $userMessage = collect($params['messages'])->last()['content'] ?? '';
        $session = $this->findOrCreateSession(
            $userId,
            $mediaId,
            $params['session_id'] ?? null,
            $userMessage
        );
        $this->saveMessage((string) $session->getKey(), ChatMessage::ROLE_USER, $userMessage);
        $buffer = '';
        $saved = false;

        // 最後一句稍後單獨接在訊息陣列結尾，這裡去掉以免重複。
        $history = $params['messages'];
        array_pop($history);

        // 參考資料取最新一份「已完成」的摘要。不能只取最新一筆：重跑摘要時會先建一筆
        // status=created、text 還是空的資料列，只看時間排序會被那筆蓋掉先前可用的摘要。
        // 沒有可用摘要時就給空字串，不退回逐字稿。
        $summaryText = $media->summaries()
            ->where('status', Summary::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->first()?->text ?? [];
        $template = TemplateFactory::create('assistant', [
            'user_prompt'      => $summaryText['long_summary']['content'] ?? '',
            'respond_language' => $request->user()->aiLanguageName(),
        ]);

        try {
            $stream = $this->streamer->stream(
                $template->getSystemPrompt(),
                $this->buildMessages($history, $userMessage)
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
     * 不限制的方案不會有這組 header（見 ChatQuotaSnapshot::headers()）。
     */
    private function withQuotaHeaders(ResponseInterface $response, ChatQuotaSnapshot $quota): ResponseInterface
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
     * @param array<int, array{role: string, content: string}> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(array $history, string $userMessage): array
    {
        // role / content 由 ChatValidator 保證必填，這裡不需再防禦性檢查。
        $messages = array_map(
            fn (array $message): array => [
                'role'    => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $message['content'],
            ],
            $history
        );
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $normalised = [];

        foreach ($messages as $message) {
            if ($normalised === [] && $message['role'] !== 'user') {
                continue;
            }

            $last = array_key_last($normalised);

            if ($last !== null && $normalised[$last]['role'] === $message['role']) {
                $normalised[$last]['content'] .= "\n\n" . $message['content'];
                continue;
            }

            $normalised[] = $message;
        }

        return $normalised;
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

    private function saveMessage(string $sessionId, string $role, string $content): void
    {
        if ($content === '') {
            return;
        }

        ChatMessage::create([
            'session_id' => $sessionId,
            'role'       => $role,
            'content'    => $content,
            'created_at' => now(),
        ]);
    }
}
