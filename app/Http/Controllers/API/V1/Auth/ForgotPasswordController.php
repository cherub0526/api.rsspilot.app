<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Auth;

use Carbon\Carbon;
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
    private const RESET_ROUTE = 'api.v1.auth.forgot-password.update';

    /** 重設連結的有效分鐘數，也是信裡寫給使用者看的數字。 */
    private const RESET_LINK_MINUTES = 60;

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

        $resetUrl = URL::temporarySignedRoute(
            self::RESET_ROUTE,
            now()->addMinutes(self::RESET_LINK_MINUTES),
            [
                'token' => $this->resetToken($user),
                'id'    => strval($user->id),
            ],
            false
        );

        Mail::to($user->email)->send(new ResetPasswordMail($resetUrl, self::RESET_LINK_MINUTES));

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

        $user = User::query()
            ->where('social_type', User::SOCIAL_TYPE_LOCAL)
            ->find($params['id']);

        if (!$user || !$this->isValidResetLink($user, $params)) {
            // 一律回在 token 欄位：前端看到連結欄位被退件，就請使用者重新申請
            throw new InvalidRequestException([
                'token' => [__('validators.controllers.auth.reset_link_invalid')],
            ]);
        }

        $user->fill(['password' => Hash::make(trim($params['password']))])->save();

        return $this->responseAccessToken(auth('jwt')->login($user));
    }

    /**
     * 重設連結的 token：綁使用者 id 與**目前的密碼雜湊**，以 app key 做 HMAC。
     *
     * - 沒有 app key 就算不出來，不能像過去的 Hash::make(id) 那樣，知道 id 就自己造一個。
     * - 密碼一改，雜湊就變，同一封信裡的連結隨即失效——連結只能用一次。
     */
    private function resetToken(User $user): string
    {
        return hash_hmac('sha256', $user->id . '|' . $user->password, (string) config('app.key'));
    }

    /**
     * 連結要同時通過以下三項檢查。
     *
     * 1. 簽章——store() 用 temporarySignedRoute 簽了 expires / id / token，這裡用同樣的參數
     *    重簽一次比對，任何一個被改過（包括把期限往後延）都對不上。前端是把網址上的參數
     *    放進 PUT body 送回來，不在 query string 上，所以不能用 hasValidSignature()。
     * 2. 期限——expires 已過就不收。
     * 3. token——對應目前的密碼雜湊，見 resetToken()。
     *
     * @param array<string, mixed> $params
     */
    private function isValidResetLink(User $user, array $params): bool
    {
        $expires = (int) $params['expires'];

        if (now()->getTimestamp() > $expires) {
            return false;
        }

        $signed = URL::temporarySignedRoute(
            self::RESET_ROUTE,
            Carbon::createFromTimestamp($expires),
            ['token' => (string) $params['token'], 'id' => (string) $params['id']],
            false
        );
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return hash_equals((string) ($query['signature'] ?? ''), (string) $params['signature'])
            && hash_equals($this->resetToken($user), (string) $params['token']);
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
