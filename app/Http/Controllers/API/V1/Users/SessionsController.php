<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Users;

use Hypervel\Http\Request;
use App\Models\ChatSession;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http204;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Schemas\Paginators;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Schemas\UserChatSessionSchema;
use App\Http\Resources\UserChatSessionResource;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class SessionsController
{
    /**
     * GET /v1/users/sessions.
     *
     * 列出當前使用者所有 ChatSession，依建立時間降冪排列。
     * 每筆 session 包含關聯 Media、訊息總數、最後兩則訊息。
     */
    #[OAT\Get(
        path: '/v1/users/sessions',
        operationId: 'api.v1.users.sessions.index',
        summary: 'List all chat sessions for the authenticated user',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            type: 'array',
                            items: new OAT\Items(ref: UserChatSessionSchema::class)
                        ),
                        new OAT\Property(property: 'links', ref: Paginators\Links::class),
                        new OAT\Property(property: 'meta', ref: Paginators\Meta::class),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = (string) $request->user()->getKey();

        $sessions = ChatSession::where('user_id', $userId)
            ->with(['media', 'messages'])
            ->withCount('messages')
            ->orderByDesc('created_at')
            ->paginate(20);

        return UserChatSessionResource::collection($sessions);
    }

    /**
     * DELETE /v1/users/sessions.
     *
     * 刪除當前使用者所有 ChatSession（soft delete）。
     */
    #[OAT\Delete(
        path: '/v1/users/sessions',
        operationId: 'api.v1.users.sessions.destroy',
        summary: 'Delete all chat sessions for the authenticated user',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        responses: [
            new OAT\Response(ref: Http204::class, response: 204),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function destroy(Request $request): ResponseInterface
    {
        $userId = (string) $request->user()->getKey();

        ChatSession::where('user_id', $userId)->delete();

        return response()->make('', 204);
    }
}
