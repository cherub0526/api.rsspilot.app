<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Oauth;

use Throwable;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http422;
use App\Validators\OauthValidator;
use App\Services\SocialAccountService;
use Psr\Http\Message\ResponseInterface;
use Hypervel\Socialite\Facades\Socialite;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use Hypervel\Socialite\Two\User as SocialiteUser;
use App\Http\Controllers\Concerns\IssuesAccessToken;
use App\Http\Controllers\Concerns\EnsuresOauthProvider;

class CallbackController extends AbstractController
{
    use EnsuresOauthProvider;
    use IssuesAccessToken;

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/oauth/{provider}/callback',
        operationId: 'api.v1.oauth.callback.store',
        summary: 'Exchange the provider authorization code for an access token',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['code', 'redirect'],
                properties: [
                    new OAT\Property(
                        property: 'code',
                        type: 'string',
                        description: 'The authorization code the provider sent to the redirect URL.',
                        example: '4/0AfJohXl...'
                    ),
                    new OAT\Property(
                        property: 'redirect',
                        type: 'string',
                        format: 'uri',
                        maxLength: 255,
                        description: 'Must be byte-for-byte the value sent to /v1/oauth/{provider}/redirect. '
                            . 'The provider rejects the exchange when the two differ.',
                        example: 'https://rsspilot.app/oauth/google/callback'
                    ),
                ]
            )
        ),
        tags: ['Auth'],
        parameters: [
            new OAT\Parameter(
                name: 'provider',
                in: 'path',
                required: true,
                description: 'OAuth provider. Only `google` is wired up right now.',
                schema: new OAT\Schema(type: 'string', enum: ['google', 'facebook'], example: 'google')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 201,
                description: 'Access token issued',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'access_token', type: 'string', example: 'eyJ0eXAiOiJKV1Qi...'),
                        new OAT\Property(property: 'token_type', type: 'string', example: 'bearer'),
                        new OAT\Property(property: 'expires_in', type: 'integer', example: 3600),
                    ]
                )
            ),
            new OAT\Response(ref: Http422::class, response: 422),
        ]
    )]
    public function store(Request $request, string $provider): ResponseInterface
    {
        // provider 來自路徑段，跟 body 的欄位併成同一份參數交給 validator，
        // 與 RedirectController 同一個形狀。
        $params = ['provider' => $provider] + $request->only(['code', 'redirect']);

        $validator = (new OauthValidator($params))->setStoreRules();

        if (!$validator->passes()) {
            throw new InvalidRequestException($validator->errors()->toArray());
        }

        $this->ensureProviderIsConfigured($provider);

        $socialUser = $this->fetchProviderUser($provider, $params['redirect']);

        $user = (new SocialAccountService())->resolveUser($provider, $socialUser);

        return $this->responseAccessToken($this->guard()->login($user), 201);
    }

    /**
     * 拿 code 跟 provider 換 token 與使用者資料。
     *
     * `code` 不必自己傳進去——Socialite 的 getCode() 讀的是當前 request 的 `code` 欄位，
     * 而它就在這支端點的 body 裡。`redirect` 則一定要覆寫：provider 會核對這次交換用的
     * redirect_uri 與當初發出授權請求時的是否逐字相同，用 config 的預設值會直接被拒。
     *
     * stateless() 的理由同 RedirectController——這是一支沒有 session 的 API。CSRF 的
     * state 由前端自行掛在授權網址上並在 callback 比對。
     *
     * @throws InvalidRequestException
     */
    private function fetchProviderUser(string $provider, string $redirect): SocialiteUser
    {
        try {
            return Socialite::driver($provider)
                ->redirectUrl($redirect)
                ->stateless()
                ->user();
        } catch (Throwable) {
            // code 過期、被用過、redirect 對不上都會落在這裡，全是呼叫端該收到的 4xx。
            // 原始回應不轉述給 client——那裡面有 provider 的錯誤細節。
            throw new InvalidRequestException(
                ['code' => [__('validators.controllers.oauth.exchange_failed')]]
            );
        }
    }
}
