<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Auth;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use Psr\Http\Message\ResponseInterface;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\IssuesAccessToken;

class RefreshController extends AbstractController
{
    use IssuesAccessToken;

    #[OAT\Post(
        path: '/v1/auth/refresh',
        operationId: 'api.v1.auth.refresh.store',
        summary: 'Exchange the current access token for a new one',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'New access token issued',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'access_token',
                            type: 'string',
                            example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'
                        ),
                        new OAT\Property(property: 'token_type', type: 'string', example: 'bearer'),
                        new OAT\Property(property: 'expires_in', type: 'integer', example: 3600),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    /**
     * 刻意用 login() 而不是 guard()->refresh()。
     *
     * JWTManager::refresh() 產出的 claims 只有 sub 與原始 iat（見 buildRefreshClaims()），
     * encode() 不補預設值，於是換發出來的 token **沒有 exp**；而 ExpiredClaim 對缺少
     * exp 的 payload 直接放行，等於發出一張永不過期的憑證。
     * login() 走的是登入那條路徑，iat / exp 齊全，也保證三支發 token 的端點形狀一致。
     *
     * 代價是 iat 會跟著換發更新，所以「從首次登入起算」的上限不存在——本來也不存在，
     * 這個套件沒有任何 validation 在檢查 refresh_ttl。見 docs/lore/auth/business-rules.md。
     */
    public function store(Request $request): ResponseInterface
    {
        return $this->responseAccessToken($this->guard()->login($request->user()));
    }
}
