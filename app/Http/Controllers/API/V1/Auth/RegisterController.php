<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Auth;

use App\Models\User;
use Hypervel\Http\Request;
use App\Services\EmailVerificationService;
use OpenApi\Attributes as OAT;
use App\Validators\AuthValidator;
use App\OpenApi\Responses\Http400;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\IssuesAccessToken;

class RegisterController extends AbstractController
{
    use IssuesAccessToken;

    public function __construct(private readonly EmailVerificationService $verification)
    {
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/auth/register',
        operationId: 'api.v1.auth.register.store',
        summary: 'Register a new user',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['email', 'password', 'password_confirmation'],
                properties: [
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
                response: 202,
                description: '帳號已建立，驗證信已寄出。刻意不回 token——'
                    . '要先通過 /v1/auth/verify 才算完成註冊。'
            ),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        $params = $request->only(['email', 'password', 'password_confirmation']);

        $v = new AuthValidator($params);
        $v->setRegisterRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = User::create([
            // account 已不是登入識別，但欄位仍在（既有資料要保留）。
            // 帶 email 進去讓舊資料的形狀不變，name 同理，之後使用者可在設定裡改。
            'account'     => $params['email'],
            'name'        => $params['email'],
            'email'       => $params['email'],
            'password'    => bcrypt($params['password']),
            'social_type' => User::SOCIAL_TYPE_LOCAL,
        ]);

        $this->verification->issueFor($user);

        // 202 而不是 201：帳號建立了，但註冊還沒完成——要通過驗證才算。
        return response()->json([], 202);
    }
}
