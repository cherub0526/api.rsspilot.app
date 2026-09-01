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
    public function store(Request $request): ResponseInterface
    {
        return $this->responseAccessToken($this->guard()->refresh());
    }
}
