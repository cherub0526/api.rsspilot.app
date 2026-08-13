<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Throwable;
use App\Models\Summary;
use Hypervel\Http\Request;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Utils\AI\Completion;
use OpenApi\Attributes as OAT;
use App\Validators\ChatValidator;
use App\Events\Chat\ChatDoneEvent;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\Events\Chat\ChatErrorEvent;
use App\Events\Chat\ChatTokenEvent;
use Hypervel\Support\Facades\Event;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Services\Prompts\TemplateFactory;
use App\Exceptions\InvalidRequestException;
use App\Services\Prompts\TemplateCompletionManager;
use App\Http\Controllers\API\V1\Media\Chat\ResolvesMedia;

class ChatController
{
    use ResolvesMedia;

    /**
     * POST /v1/media/{mediaId}/chat.
     *
     * 接收使用者訊息，向 OpenRouter 發送串流請求。
     * 每個 token 透過 ChatTokenEvent 廣播給對應的 SSE 長連線。
     * 回傳時機：完整回應產生後（或發生錯誤時）。
     *
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
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

        // 最後一句由 completeStream() 以 $userMessage 帶入結尾，這裡要去掉以免重複。
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
            'messages'         => $history,
            'respond_language' => $request->user()->aiLanguageName(),
        ]);

        $manager = new TemplateCompletionManager(Completion::make(), $template);
        $psrResponse = $manager->completeStream($userMessage);

        try {
            $body = $psrResponse->getBody();
            $chunkBuffer = '';

            while (!$body->eof()) {
                $chunk = $body->read(1024);

                if ($chunk === '') {
                    break;
                }

                $chunkBuffer .= $chunk;
                $lines = explode("\n", $chunkBuffer);
                $chunkBuffer = array_pop($lines) ?: '';

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = substr($line, 6);

                    if ($data === '[DONE]') {
                        $this->saveMessage((string) $session->getKey(), ChatMessage::ROLE_AI, $buffer);
                        $saved = true;
                        Event::dispatch(new ChatDoneEvent($userId, $mediaId));

                        return response()->json(['status' => 'done', 'session_id' => (string) $session->getKey()]);
                    }

                    $json = json_decode($data, true);
                    $token = $json['choices'][0]['delta']['content'] ?? null;

                    if ($token !== null) {
                        $buffer .= $token;
                        Event::dispatch(new ChatTokenEvent($token, $userId, $mediaId));
                    }
                }
            }

            // @phpstan-ignore-next-line Condition is false if [DONE] was processed
            if (!$saved) {
                $this->saveMessage((string) $session->getKey(), ChatMessage::ROLE_AI, $buffer);
                $saved = true;
            }
            Event::dispatch(new ChatDoneEvent($userId, $mediaId));
        } catch (Throwable $e) {
            if (!$saved) {
                $this->saveMessage((string) $session->getKey(), ChatMessage::ROLE_AI, $buffer);
            }
            Event::dispatch(new ChatErrorEvent($e->getMessage(), $userId, $mediaId));
            throw $e;
        }

        return response()->json(['status' => 'done', 'session_id' => (string) $session->getKey()]);
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
