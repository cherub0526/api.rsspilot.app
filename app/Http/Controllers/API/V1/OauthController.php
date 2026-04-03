<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Models\Oauth;
use App\Validators\OauthValidator;
use Hypervel\Http\Request;
use Hypervel\Socialite\Facades\Socialite;

class OauthController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    public function callback(Request $request)
    {
        $params = $request->only(['provider', 'code']);

        $v = new OauthValidator($params);
        $v->setStoreRules();

        if (! $v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = Socialite::driver($params['provider'])->stateless()->user();

        $userData = [
            'id' => $user->getId(),
            'username' => $user->getName(),
            'email' => $user->getEmail(),
            'avatar' => $user->getAvatar(),
        ];

        if (! $oauth = Oauth::where('provider_id', $userData['id'])->where('provider', $params['provider'])->first()) {
            $oauth = Oauth::create([
                'provider' => $params['provider'],
                'provider_id' => $userData['id'],
                'token' => $user->token,
                'refresh_token' => $user->refreshToken ?? null,
                'expires_in' => $user->expiresIn ?? null,
                'data' => $user,
            ]);
        }
    }
}
