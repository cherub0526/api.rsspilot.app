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
                required: ['email', 'password'],
                properties: [
                    new OAT\Property(
                        property: 'email',
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
        $params = $request->only(['email', 'password']);

        $v = new AuthValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        // 密碼登入只認 local 帳號——同一個 email 可能同時存在 google / facebook 的
        // 社群帳號，那些沒有密碼，不該被密碼流程撈到。
        $user = User::query()
            ->where('email', $params['email'])
            ->where('social_type', User::SOCIAL_TYPE_LOCAL)
            ->first();

        if ($user === null) {
            // 該 email 只有社群帳號 → 使用者其實從未設過密碼。回「密碼錯誤」會讓人
            // 一直重打，所以給專屬代碼讓前端引導去設一組。
            $hasSocial = User::query()->where('email', $params['email'])->exists();

            if ($hasSocial) {
                throw InvalidRequestException::withCode(
                    'password_not_set',
                    [],
                    ['email' => [__('validators.controllers.auth.password_not_set')]]
                );
            }

            throw new InvalidRequestException(
                ['password' => [__('validators.controllers.auth.invalid_credentials')]]
            );
        }

        if (!$this->guard()->attempt($params)) {
            throw new InvalidRequestException(
                ['password' => [__('validators.controllers.auth.invalid_credentials')]]
            );
        }

        // 密碼對了但 email 沒驗過：不發 token，讓前端接回驗證流程並重寄。
        // 既有使用者在遷移時已被 grandfather 成已驗證，所以這裡只會擋到
        // 「註冊到一半跑掉」的人。
        if ($user->email_verified_at === null) {
            throw InvalidRequestException::withCode(
                'email_unverified',
                [],
                ['email' => [__('validators.controllers.auth.email_unverified')]]
            );
        }

        return $this->responseAccessToken($this->guard()->login($user));
    }
}
