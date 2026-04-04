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

class AuthController extends AbstractController
{
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

        return $this->responseAccessToken(auth()->login($request->user()));
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/auth/register',
        operationId: 'api.v1.auth.register',
        summary: 'Register a new user',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['account', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OAT\Property(
                        property: 'account',
                        type: 'string',
                        maxLength: 255,
                        minLength: 6,
                        example: 'johndoe'
                    ),
                    new OAT\Property(
                        property: 'email',
                        type: 'string',
                        format: 'email',
                        maxLength: 255,
                        example: 'johndoe@example.com'
                    ),
                    new OAT\Property(
                        property: 'password',
                        type: 'string',
                        minLength: 8,
                        example: 'secret123'
                    ),
                    new OAT\Property(
                        property: 'password_confirmation',
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
                response: 201,
                description: 'User registered and access token issued',
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
    public function register(Request $request): ResponseInterface
    {
        $params = $request->only(['account', 'email', 'password', 'password_confirmation']);

        $v = new AuthValidator($params);
        $v->setRegisterRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = User::query()
            ->where('account', $params['account'])
            ->where('social_type', User::SOCIAL_TYPE_LOCAL)
            ->first();

        if (!$user) {
            $user = User::create([
                'account'     => $params['account'],
                'name'        => $params['account'],
                'email'       => $params['email'],
                'password'    => bcrypt($params['password']),
                'social_type' => User::SOCIAL_TYPE_LOCAL,
            ]);
        }

        $token = $this->guard()->login($user);

        return $this->responseAccessToken($token, 201);
    }

    public function refresh(Request $request): ResponseInterface
    {
        $token = $this->guard()->refresh();

        return $this->responseAccessToken($token);
    }

    public function logout(Request $request): ResponseInterface
    {
        return response()->make(self::RESPONSE_OK, 200);
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
