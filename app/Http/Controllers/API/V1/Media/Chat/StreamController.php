<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media\Chat;

use Hypervel\Http\Request;
use Swoole\Coroutine\Channel;
use OpenApi\Attributes as OAT;
use Hypervel\Http\StreamOutput;
use App\Events\Chat\ChatDoneEvent;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\Events\Chat\ChatErrorEvent;
use App\Events\Chat\ChatTokenEvent;
use Hypervel\Support\Facades\Event;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\Http\Controllers\Concerns\SendsSseHeaders;

class StreamController
{
    use ResolvesMedia;
    use SendsSseHeaders;

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
        operationId: 'api.v1.media.chat.stream.show',
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
    public function show(Request $request, string $mediaId): ResponseInterface
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
                // @phpstan-ignore-next-line $active is passed by reference and modified in finally block
                if ($active && $event->userId === $userId && $event->mediaId === $mediaId) {
                    $channel->push(['type' => 'token', 'token' => $event->token]);
                }
            };

            $doneListener = function (ChatDoneEvent $event) use ($channel, $userId, $mediaId, &$active): void {
                // @phpstan-ignore-next-line $active is passed by reference and modified in finally block
                if ($active && $event->userId === $userId && $event->mediaId === $mediaId) {
                    $channel->push(['type' => 'done']);
                }
            };

            $errorListener = function (ChatErrorEvent $event) use ($channel, $userId, $mediaId, &$active): void {
                // @phpstan-ignore-next-line $active is passed by reference and modified in finally block
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
