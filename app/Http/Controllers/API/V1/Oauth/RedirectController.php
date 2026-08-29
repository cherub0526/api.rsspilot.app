<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Oauth;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http422;
use App\Validators\OauthValidator;
use Psr\Http\Message\ResponseInterface;
use Hypervel\Socialite\Facades\Socialite;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\EnsuresOauthProvider;

class RedirectController extends AbstractController
{
    use EnsuresOauthProvider;

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/oauth/{provider}/redirect',
        operationId: 'api.v1.oauth.redirect.store',
        summary: 'Build the provider authorization URL to start an OAuth sign-in',
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['redirect'],
                properties: [
                    new OAT\Property(
                        property: 'redirect',
                        type: 'string',
                        format: 'uri',
                        maxLength: 255,
                        description: 'Where the provider sends the user back to. '
                            . 'Must be registered with the provider beforehand.',
                        example: 'https://rsspilot.app/oauth/callback'
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
                response: 200,
                description: 'Authorization URL',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'url',
                            type: 'string',
                            format: 'uri',
                            example: 'https://accounts.google.com/o/oauth2/auth?client_id=...'
                                . '&redirect_uri=https%3A%2F%2Frsspilot.app%2Foauth%2Fcallback'
                                . '&scope=openid+profile+email&response_type=code'
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http422::class, response: 422),
        ]
    )]
    public function store(Request $request, string $provider): ResponseInterface
    {
        // provider 來自路徑段，跟 body 的 redirect 併成同一份參數交給 validator，
        // 好讓「無效的 provider」與「缺少 redirect」走同一條 400 的路。
        $params = ['provider' => $provider] + $request->only(['redirect']);

        $validator = (new OauthValidator($params))->setRedirectRules();

        if (!$validator->passes()) {
            throw new InvalidRequestException($validator->errors()->toArray());
        }

        $this->ensureProviderIsConfigured($provider);

        return response()->json([
            'url' => $this->authorizationUrl($provider, $params['redirect']),
        ]);
    }

    /**
     * 組出 provider 的授權網址。
     *
     * Socialite 的 redirect() 回的是 PSR-7 response 而不是 Laravel 的
     * RedirectResponse，沒有 getTargetUrl() 可用；getAuthUrl() 與
     * buildAuthUrlFromBase() 又都是 protected。取字串只能讀 Location header。
     *
     * stateless() 是必要的：帶 state 的模式會 `$request->session()->put()`，
     * 而這是一支無 session 的 API 端點。CSRF 的防護因此落在前端——由它自己
     * 在 redirect 網址上帶可驗證的參數。
     */
    private function authorizationUrl(string $provider, string $redirect): string
    {
        return Socialite::driver($provider)
            ->redirectUrl($redirect)
            ->stateless()
            ->redirect()
            ->getHeaderLine('Location');
    }
}
