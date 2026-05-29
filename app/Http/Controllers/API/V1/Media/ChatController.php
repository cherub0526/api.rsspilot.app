<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Throwable;
use App\Models\Media;
use Hypervel\Http\Request;
use App\Utils\AI\Completion;
use App\Utils\Const\ISO6391;
use Swoole\Coroutine\Channel;
use OpenApi\Attributes as OAT;
use Hypervel\Http\StreamOutput;
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

class ChatController
{
    /**
     * 為 SSE streaming response 建立含 CORS 的 headers。
     *
     * response()->stream() 透過 Swoole socket 直接送出 headers，
     * 發生在 CORS middleware after-phase 之前，所以必須在此手動帶入。
     */
    private function sseHeaders(Request $request): array
    {
        $origin = $request->header('Origin', '');
        $allowedOrigins = config('cors.allowed_origins', ['*']);

        if (in_array('*', $allowedOrigins)) {
            $corsOrigin = '*';
        } elseif (in_array($origin, $allowedOrigins)) {
            $corsOrigin = $origin;
        } else {
            $corsOrigin = '';
        }

        $headers = [
            'Cache-Control'     => 'no-cache',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        if ($corsOrigin !== '') {
            $headers['Access-Control-Allow-Origin'] = $corsOrigin;
            if ($corsOrigin !== '*') {
                $headers['Vary'] = 'Origin';
            }
        }

        return $headers;
    }

    /**
     * 驗證媒體存取權限，回傳 Media 實例。
     *
     * @throws NotFoundHttpException
     */
    private function resolveMedia(Request $request, string $mediaId): Media
    {
        $media = Media::find($mediaId);

        if (!$media) {
            throw new NotFoundHttpException();
        }

        $source = $media->source;
        $hasAccess = ($source?->free ?? false)
            || ($source && $request->user()->sources()->where('sources.id', $source->getKey())->exists())
            || $request->user()->media()->where('media.id', $mediaId)->exists();

        if (!$hasAccess) {
            throw new NotFoundHttpException();
        }

        return $media;
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

        $template = TemplateFactory::create('assistant', [
            'user_prompt'      => $media->captions()->orderByDesc('primary')->first()->text ?? '',
            'messages'         => array_pop($params['messages']),
            'respond_language' => ISO6391::getNameByCode($request->user()->setting()->first()->data['ai']['language']),
        ]);

        $manager = new TemplateCompletionManager(Completion::make(), $template);
        $psrResponse = $manager->completeStream($userMessage);

        try {
            $body = $psrResponse->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $chunk = $body->read(1024);

                if ($chunk === '') {
                    break;
                }

                $buffer .= $chunk;
                $lines = explode("\n", $buffer);
                $buffer = array_pop($lines) ?: '';

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = substr($line, 6);

                    if ($data === '[DONE]') {
                        Event::dispatch(new ChatDoneEvent($userId, $mediaId));

                        return response()->json(['status' => 'done']);
                    }

                    $json = json_decode($data, true);
                    $token = $json['choices'][0]['delta']['content'] ?? null;

                    if ($token !== null) {
                        Event::dispatch(new ChatTokenEvent($token, $userId, $mediaId));
                    }
                }
            }

            Event::dispatch(new ChatDoneEvent($userId, $mediaId));
        } catch (Throwable $e) {
            Event::dispatch(new ChatErrorEvent($e->getMessage(), $userId, $mediaId));
            throw $e;
        }

        return response()->json(['status' => 'done']);
    }

    /**
     * GET /v1/media/{mediaId}/chat/stream.
     *
     * 建立 SSE 長連線，即時接收此使用者在此媒體上的 AI 回覆 token。
     *
     * 流程：
     *  1. 開啟長連線，送出 connected 事件
     *  2. 為此連線建立專屬 Swoole Channel
     *  3. 動態監聽 ChatTokenEvent / ChatDoneEvent / ChatErrorEvent
     *     （依 userId + mediaId 過濾，只接收屬於自己的事件）
     *  4. 30 秒 timeout 發 heartbeat，前端斷線則退出迴圈
     *
     * @throws NotFoundHttpException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/chat/stream',
        operationId: 'api.v1.media.chat.stream',
        summary: 'SSE long connection to receive AI reply tokens',
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'SSE stream: token / done / error events',
                content: new OAT\MediaType(
                    mediaType: 'text/event-stream',
                    schema: new OAT\Schema(
                        type: 'string',
                        example: "data: {\"type\":\"token\",\"token\":\"Hello\"}\n\ndata: {\"type\":\"done\"}\n\n"
                    )
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function stream(Request $request, string $mediaId): ResponseInterface
    {
        $this->resolveMedia($request, $mediaId);

        $userId = (string) $request->user()->getKey();

        return response()->stream(function (StreamOutput $output) use ($userId, $mediaId): void {
            // 送出連線成功事件
            $output->write('data: ' . json_encode(['type' => 'connected']) . "\n\n");

            // 每條連線有自己的 Channel（緩衝 50 個 payload）
            $channel = new Channel(50);
            $active = true;

            // 用閉包過濾：只處理屬於此 user + media 的事件
            $tokenListener = function (ChatTokenEvent $event) use ($channel, $userId, $mediaId, &$active): void {
                if ($active && $event->userId === $userId && $event->mediaId === $mediaId) {
                    $channel->push(['type' => 'token', 'token' => $event->token]);
                }
            };

            $doneListener = function (ChatDoneEvent $event) use ($channel, $userId, $mediaId, &$active): void {
                if ($active && $event->userId === $userId && $event->mediaId === $mediaId) {
                    $channel->push(['type' => 'done']);
                }
            };

            $errorListener = function (ChatErrorEvent $event) use ($channel, $userId, $mediaId, &$active): void {
                if ($active && $event->userId === $userId && $event->mediaId === $mediaId) {
                    $channel->push(['type' => 'error', 'message' => $event->message]);
                }
            };

            Event::listen(ChatTokenEvent::class, $tokenListener);
            Event::listen(ChatDoneEvent::class, $doneListener);
            Event::listen(ChatErrorEvent::class, $errorListener);

            try {
                while (true) {
                    // 等待 30 秒；timeout → 發 heartbeat
                    $payload = $channel->pop(30.0);

                    if ($payload === false) {
                        if (!$output->write(": heartbeat\n\n")) {
                            break; // 前端斷線
                        }
                        continue;
                    }

                    if (!$output->write('data: ' . json_encode($payload) . "\n\n")) {
                        break; // 前端斷線
                    }
                }
            } finally {
                // 停用監聽器、關閉 Channel
                $active = false;
                $channel->close();
            }
        }, $this->sseHeaders($request));
    }
}
