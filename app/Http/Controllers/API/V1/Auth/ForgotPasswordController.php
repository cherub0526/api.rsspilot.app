<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Auth;

use App\Models\User;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\Mail\ResetPasswordMail;
use Hypervel\Support\Facades\URL;
use App\OpenApi\Responses\Http400;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Mail;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\InvalidRequestException;
use App\Validators\ForgotPasswordValidator;
use App\Http\Controllers\AbstractController;

class ForgotPasswordController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/auth/forgot-password',
        operationId: 'api.v1.auth.forgot-password.store',
        summary: 'Send password reset email',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['email'],
                properties: [
                    new OAT\Property(
                        property: 'email',
                        type: 'string',
                        maxLength: 255,
                        minLength: 6,
                        example: 'user@example.com'
                    ),
                ]
            )
        ),
        tags: ['Auth'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Reset email sent',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'message',
                            type: 'string',
                            example: 'We have emailed your password reset link.'
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        $params = $request->only(['email']);

        $v = new ForgotPasswordValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        // 只找 local 帳號：社群帳號沒有密碼可重設
        if (!$user = User::where('email', $params['email'])
            ->where('social_type', User::SOCIAL_TYPE_LOCAL)
            ->first()) {
            // To prevent user enumeration, we'll return a generic success response even if the user doesn't exist.
            throw new InvalidRequestException(['email' => [__('validators.controllers.auth.invalid_credentials')]]);
        }

        $stringId = strval($user->id);

        // 生成一個唯一且加密安全的 Token
        $token = Hash::make($stringId);

        $minutes = 60;

        $resetUrl = URL::temporarySignedRoute(
            'api.v1.auth.forgot-password.update',
            now()->addMinutes($minutes),
            [
                'token' => $token, // 使用生成的唯一 Token
                'id'    => $stringId, // 傳遞 id 以便在重設頁面使用
            ],
            false
        );

        Mail::to($user->email)->send(new ResetPasswordMail($resetUrl, $minutes));

        return response()->json([
            'message' => __('passwords.sent'),
        ]);
    }

    /**
     * @throws InvalidRequestException
     */
    public function update(Request $request)
    {
        $params = $request->only(['expires', 'id', 'token', 'signature', 'password', 'password_confirmation']);

        $v = new ForgotPasswordValidator($params);
        $v->setUpdateRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!Hash::check($params['id'], $params['token'])) {
            throw new InvalidRequestException(['token' => 'Invalid token.']);
        }

        $user = User::query()->find($params['id']);
        $user->fill(['password' => Hash::make(trim($params['password']))])->save();

        return $this->responseAccessToken(auth('jwt')->login($user));
    }

    private function responseAccessToken(string $token): ResponseInterface
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
        ]);
    }
}
