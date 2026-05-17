<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Oauth;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\Validators\OauthValidator;
use Hypervel\Socialite\Facades\Socialite;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;

class OauthController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/auth/oauth/callback',
        operationId: 'api.v1.auth.oauth.callback',
        summary: 'Handle OAuth provider callback and bind social account',
        tags: ['Auth'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['provider', 'code'],
                properties: [
                    new OAT\Property(property: 'provider', type: 'string', enum: ['facebook', 'google'], example: 'google'),
                    new OAT\Property(property: 'code', type: 'string', example: '4/0AfJohXl...'),
                ]
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'OAuth account bound successfully'),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function callback(Request $request)
    {
        $params = $request->only(['provider', 'code']);

        $v = new OauthValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = Socialite::driver($params['provider'])->stateless()->user();

        $userData = [
            'id'       => $user->getId(),
            'username' => $user->getName(),
            'email'    => $user->getEmail(),
            'avatar'   => $user->getAvatar(),
        ];

        if (!$oauth = Oauth::where('provider_id', $userData['id'])->where('provider', $params['provider'])->first()) {
            $oauth = Oauth::create([
                'provider'      => $params['provider'],
                'provider_id'   => $userData['id'],
                'token'         => $user->token,
                'refresh_token' => $user->refreshToken ?? null,
                'expires_in'    => $user->expiresIn ?? null,
                'data'          => $user,
            ]);
        }
    }
}
