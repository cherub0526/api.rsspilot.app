<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Auth;

use App\Models\User;
use Hypervel\Http\Request;
use App\Validators\GoogleValidator;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\InvalidRequestException;

class GoogleController
{
    #[OAT\Post(
        path: '/v1/auth/google',
        operationId: 'api.v1.auth.google.store',
        summary: 'Login or register via Google OAuth token',
        tags: ['Auth'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['access_token', 'avatar_url', 'email', 'name'],
                properties: [
                    new OAT\Property(property: 'access_token', type: 'string', example: 'ya29.a0AfH6SMBx...'),
                    new OAT\Property(property: 'avatar_url', type: 'string', format: 'uri', example: 'https://lh3.googleusercontent.com/a/photo.jpg'),
                    new OAT\Property(property: 'email', type: 'string', format: 'email', example: 'user@gmail.com'),
                    new OAT\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OAT\Property(property: 'provider_id', type: 'string', example: '112345678901234567890'),
                ]
            )
        ),
        responses: [
            new OAT\Response(
                response: 201,
                description: 'Access token issued',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'access_token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'),
                        new OAT\Property(property: 'token_type', type: 'string', example: 'bearer'),
                        new OAT\Property(property: 'expires_in', type: 'integer', example: 3600),
                    ]
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function store(Request $request)
    {
        $params = $request->only(['access_token', 'avatar_url', 'email', 'name', 'provider_id']);

        $v = new GoogleValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = User::query()
            ->where('social_type', User::SOCIAL_TYPE_GOOGLE)
            ->where('provider_id', $params['provider_id'])
            ->first();

        if (!$user) {
            $user = User::create([
                'account'     => $params['provider_id'],
                'password'    => bcrypt($params['provider_id']),
                'name'        => $params['name'],
                'email'       => $params['email'],
                'social_type' => User::SOCIAL_TYPE_GOOGLE,
                'provider_id' => $params['provider_id'],
                'avatar'      => $params['avatar_url'],
            ]);
        }

        $token = $this->guard()->login($user);

        return $this->responseAccessToken($token, 201);
    }

    private function guard()
    {
        return auth('jwt');
    }

    private function responseAccessToken(string $token, int $statusCode = 200): ResponseInterface
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
        ], $statusCode);
    }
}
