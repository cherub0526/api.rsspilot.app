<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media\Chat;

use Hypervel\Http\Request;
use App\Models\ChatSession;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Schemas\Paginators;
use App\OpenApi\Parameters\Path\MediaId;
use App\Exceptions\NotFoundHttpException;
use App\OpenApi\Schemas\ChatSessionSchema;
use App\Http\Resources\ChatSessionResource;
use App\OpenApi\Schemas\ChatSessionDetailSchema;
use App\Http\Resources\ChatSessionDetailResource;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class SessionsController
{
    use ResolvesMedia;

    /**
     * GET /v1/media/{mediaId}/chat/sessions.
     *
     * 回傳此使用者在此媒體上所有的 ChatSession，依 updated_at 降冪排列。
     *
     * @throws NotFoundHttpException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/chat/sessions',
        operationId: 'api.v1.media.chat.sessions.index',
        summary: 'List chat sessions for a media',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            type: 'array',
                            items: new OAT\Items(ref: ChatSessionSchema::class)
                        ),
                        new OAT\Property(property: 'links', ref: Paginators\Links::class),
                        new OAT\Property(property: 'meta', ref: Paginators\Meta::class),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function index(Request $request, string $mediaId): AnonymousResourceCollection
    {
        $this->resolveMedia($request, $mediaId);

        $userId = (string) $request->user()->getKey();

        $sessions = ChatSession::where('user_id', $userId)
            ->where('media_id', $mediaId)
            ->orderByDesc('updated_at')
            ->paginate(20);

        return ChatSessionResource::collection($sessions);
    }

    /**
     * GET /v1/media/{mediaId}/chat/sessions/{sessionId}.
     *
     * 回傳指定 ChatSession 與其訊息記錄。
     *
     * @throws NotFoundHttpException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/chat/sessions/{sessionId}',
        operationId: 'api.v1.media.chat.sessions.show',
        summary: 'Get a chat session with its messages',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
            new OAT\Parameter(
                name: 'sessionId',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string', example: '01jsvgt3prpypqwex4wj78bznk')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(ref: ChatSessionDetailSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function show(Request $request, string $mediaId, string $sessionId): ChatSessionDetailResource
    {
        $this->resolveMedia($request, $mediaId);

        $userId = (string) $request->user()->getKey();

        $session = ChatSession::with('messages')
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->where('media_id', $mediaId)
            ->first();

        if (!$session) {
            throw new NotFoundHttpException();
        }

        return new ChatSessionDetailResource($session);
    }
}
