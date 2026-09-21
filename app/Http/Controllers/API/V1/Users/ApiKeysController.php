<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Users;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;

/**
 * 使用者自己產生的 API key（sanctum personal access token）。
 *
 * 這些端點自己走 jwt guard——產生與刪除 key 是帳號層級的操作，不該用一把 key
 * 去產生下一把 key：那等於讓一把外洩的 key 可以自我續命，撤銷也就失去意義。
 */
class ApiKeysController
{
    /** 名稱長度上限。只是給使用者辨識用的標籤，不必長。 */
    private const int NAME_MAX = 60;

    /** 一個帳號最多幾把。擋的是無限產生，不是正常使用。 */
    private const int MAX_KEYS = 10;

    #[OAT\Get(
        path: '/v1/users/api-keys',
        operationId: 'api.v1.users.api-keys.index',
        summary: "List the authenticated user's API keys",
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Keys, newest first. The token itself is never returned.',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            type: 'array',
                            items: new OAT\Items(
                                properties: [
                                    new OAT\Property(property: 'id', type: 'integer', example: 3),
                                    new OAT\Property(property: 'name', type: 'string', example: 'Claude Desktop'),
                                    new OAT\Property(
                                        property: 'last_used_at',
                                        type: 'string',
                                        format: 'date-time',
                                        nullable: true
                                    ),
                                    new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request): ResponseInterface
    {
        $keys = $request->user()->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token): array => [
                'id'           => (int) $token->getKey(),
                'name'         => (string) $token->getAttribute('name'),
                'last_used_at' => $token->getAttribute('last_used_at')?->toIso8601String(),
                'created_at'   => $token->getAttribute('created_at')?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $keys]);
    }

    /**
     * 產生一把新的 key。
     *
     * **明文只在這個回應裡出現一次**，之後資料庫裡只剩 sha256。使用者沒存到就
     * 只能刪掉重產——這是 personal access token 的標準作法，也是它值得信任的
     * 原因：連我們自己都讀不回來。
     *
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/users/api-keys',
        operationId: 'api.v1.users.api-keys.store',
        summary: 'Create an API key',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['name'],
                properties: [
                    new OAT\Property(property: 'name', type: 'string', maxLength: 60, example: 'Claude Desktop'),
                ]
            )
        ),
        tags: ['Users'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The plaintext token, returned only once',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'id', type: 'integer', example: 3),
                        new OAT\Property(property: 'name', type: 'string', example: 'Claude Desktop'),
                        new OAT\Property(property: 'token', type: 'string', example: 'rsp_3|abc123...'),
                        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidRequestException(['name' => [__('validators.controllers.api_keys.name')]]);
        }

        $user = $request->user();

        if ($user->tokens()->count() >= self::MAX_KEYS) {
            throw new InvalidRequestException([
                'name' => [__('validators.controllers.api_keys.limit', ['max' => self::MAX_KEYS])],
            ]);
        }

        $token = $user->createToken($name);

        return response()->json([
            'id'         => (int) $token->accessToken->getKey(),
            'name'       => $name,
            'token'      => $token->plainTextToken,
            'created_at' => $token->accessToken->getAttribute('created_at')?->toIso8601String(),
        ]);
    }

    /**
     * 刪除一把 key，當場失效。
     *
     * 只刪得掉自己的：查詢一律先綁 `$request->user()->tokens()`，不是先找 token
     * 再比對持有者——後者只要少寫一個判斷就變成任何人都刪得掉任何一把。
     *
     * @throws NotFoundHttpException
     */
    #[OAT\Delete(
        path: '/v1/users/api-keys/{id}',
        operationId: 'api.v1.users.api-keys.destroy',
        summary: 'Revoke an API key',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OAT\Parameter(name: 'id', in: 'path', required: true, schema: new OAT\Schema(type: 'integer')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'OK'),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(response: 404, description: 'Key not found'),
        ]
    )]
    public function destroy(Request $request, string $id): ResponseInterface
    {
        $deleted = $request->user()->tokens()->whereKey((int) $id)->delete();

        if (!$deleted) {
            throw new NotFoundHttpException();
        }

        return response()->json(['status' => 'ok']);
    }
}
