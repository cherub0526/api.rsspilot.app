<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\User;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\Validators\AuthValidator;
use App\OpenApi\Responses\Http400;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\IssuesAccessToken;

class AuthController extends AbstractController
{
    use IssuesAccessToken;

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/auth',
        operationId: 'api.v1.auth.store',
        summary: 'Login and obtain access token',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['account', 'password'],
                properties: [
                    new OAT\Property(
                        property: 'account',
                        type: 'string',
                        maxLength: 255,
                        minLength: 6,
                        example: 'johndoe'
                    ),
                    new OAT\Property(
                        property: 'password',
                        type: 'string',
                        minLength: 8,
                        example: 'secret123'
                    ),
                ]
            )
        ),
        tags: ['Auth'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Access token issued',
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
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        $params = $request->only(['account', 'password']);

        $v = new AuthValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$this->guard()->attempt($params)) {
            throw new InvalidRequestException(['password' => [__('validators.controllers.auth.invalid_credentials')]]);
        }

        $user = User::query()
            ->where('account', $params['account'])
            ->where('social_type', User::SOCIAL_TYPE_LOCAL)
            ->first();

        return $this->responseAccessToken($this->guard()->login($user));
    }
}
