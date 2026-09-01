<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Auth;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use Psr\Http\Message\ResponseInterface;
use App\Http\Controllers\AbstractController;

class LogoutController extends AbstractController
{
    #[OAT\Post(
        path: '/v1/auth/logout',
        operationId: 'api.v1.auth.logout.store',
        summary: 'Log the current user out',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OAT\Response(response: 200, description: 'OK.'),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        return response()->make(self::RESPONSE_OK, 200);
    }
}
