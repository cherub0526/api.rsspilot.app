<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Auth;

use App\Models\User;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\Validators\AuthValidator;
use App\OpenApi\Responses\Http400;
use Psr\Http\Message\ResponseInterface;
use App\Services\EmailVerificationService;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\IssuesAccessToken;

/**
 * 註冊後的 email 驗證。
 *
 * 兩種憑證擇一：使用者手打的 6 位數 code（帶 email），或信件連結的 token。
 * 兩者是同一筆 row，用掉一個另一個即失效。驗證成功才發 token——註冊本身不發。
 */
class VerifyController extends AbstractController
{
    use IssuesAccessToken;

    public function __construct(private readonly EmailVerificationService $verification)
    {
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/auth/verify',
        operationId: 'api.v1.auth.verify.store',
        summary: '以驗證碼或信件 token 完成 email 驗證，成功後發放 token',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                properties: [
                    new OAT\Property(property: 'email', type: 'string', format: 'email', example: 'johndoe@example.com'),
                    new OAT\Property(property: 'code', type: 'string', maxLength: 6, minLength: 6, example: '048213'),
                    new OAT\Property(property: 'token', type: 'string', example: 'a1b2c3...'),
                ]
            )
        ),
        tags: ['Auth'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Email verified and access token issued',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'access_token', type: 'string'),
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
        // token 優先：信件連結進來時不會帶 email
        if ($request->input('token') !== null) {
            $v = new AuthValidator($request->only(['token']));
            $v->setVerifyTokenRules();

            if (!$v->passes()) {
                throw new InvalidRequestException($v->errors()->toArray());
            }

            $user = $this->verification->verifyByToken((string) $request->input('token'));

            return $this->responseAccessToken($this->guard()->login($user));
        }

        $params = $request->only(['email', 'code']);

        $v = new AuthValidator($params);
        $v->setVerifyRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = $this->resolveLocalUser($params['email']);
        $user = $this->verification->verifyByCode($user, (string) $params['code']);

        return $this->responseAccessToken($this->guard()->login($user));
    }

    /**
     * 重寄驗證碼。冷卻由 Service 判斷——前端的倒數只是體驗，擋不住直接打 API。
     *
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/auth/verify/resend',
        operationId: 'api.v1.auth.verify.resend.store',
        summary: '重新寄送 email 驗證碼',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['email'],
                properties: [
                    new OAT\Property(property: 'email', type: 'string', format: 'email'),
                ]
            )
        ),
        tags: ['Auth'],
        responses: [
            new OAT\Response(response: 202, description: '驗證信已重新寄出'),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function resend(Request $request): ResponseInterface
    {
        $params = $request->only(['email']);

        $v = new AuthValidator($params);
        $v->setResendRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $this->verification->resendFor($this->resolveLocalUser($params['email']));

        return response()->json([], 202);
    }

    /**
     * 找出該 email 的 local 帳號。
     *
     * 找不到、或已經驗證過，一律回 code_expired 而不是「查無此人」——
     * 這支端點不需要登入就能打，回答「這個 email 存不存在」等於送出使用者名單。
     *
     * @throws InvalidRequestException
     */
    private function resolveLocalUser(string $email): User
    {
        $user = User::query()
            ->where('email', $email)
            ->where('social_type', User::SOCIAL_TYPE_LOCAL)
            ->whereNull('email_verified_at')
            ->first();

        if ($user === null) {
            throw InvalidRequestException::withCode(
                'code_expired',
                [],
                ['code' => [__('validators.controllers.auth.code_expired')]]
            );
        }

        return $user;
    }
}
