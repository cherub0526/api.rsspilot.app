<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Oauth;
use Hypervel\Support\Facades\DB;
use Hypervel\Socialite\Two\User as SocialiteUser;

class SocialAccountService
{
    /**
     * 用 provider 回傳的身分換出本地 User，並保存這次拿到的憑證。
     *
     * 資料刻意落在兩個地方，各自有不同的用途：
     *   - `users.social_type` + `users.provider_id`：登入身分的來源。POST /v1/auth/google
     *     查的也是這兩欄，兩支端點因此會收斂到同一個帳號，不會各自長出一份使用者。
     *   - `oauths`：provider 的 access token / refresh token。日後要代表使用者呼叫
     *     Google API 時只有這裡有料，登入本身用不到。
     *
     * 包在同一個 transaction 裡：建了 user 卻沒存到憑證，下次登入不會補寫（user 已存在），
     * 那筆憑證就永遠缺席了。
     */
    public function resolveUser(string $provider, SocialiteUser $socialUser): User
    {
        return DB::transaction(function () use ($provider, $socialUser) {
            $user = $this->firstOrCreateUser($provider, $socialUser);

            $this->storeCredentials($provider, $user, $socialUser);

            return $user;
        });
    }

    /**
     * provider 的字串值與 User::SOCIAL_TYPE_* 相同（'google'、'facebook'），
     * 所以 social_type 直接沿用，不需要另一張對照表。
     */
    private function firstOrCreateUser(string $provider, SocialiteUser $socialUser): User
    {
        $providerId = (string) $socialUser->getId();

        $user = User::query()
            ->where('social_type', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($user) {
            return $user;
        }

        // 密碼欄位不可為 null，但社群帳號永遠不會走密碼登入；沿用 GoogleController
        // 既有做法以 provider_id 雜湊填充，讓兩支端點建出來的使用者形狀一致。
        return User::create([
            'account'     => $providerId,
            'password'    => bcrypt($providerId),
            'name'        => $this->truncate($socialUser->getName(), User::NAME_MAX_LENGTH),
            'email'       => $socialUser->getEmail(),
            'social_type' => $provider,
            'provider_id' => $providerId,
            'avatar'      => $this->truncate($socialUser->getAvatar(), User::AVATAR_MAX_LENGTH),
        ]);
    }

    /**
     * 同一組 (provider, provider_id) 只留一列，重複登入就更新憑證。
     */
    private function storeCredentials(string $provider, User $user, SocialiteUser $socialUser): void
    {
        Oauth::query()->updateOrCreate(
            [
                'provider'    => $provider,
                'provider_id' => (string) $socialUser->getId(),
            ],
            [
                'user_id'       => $user->getKey(),
                'token'         => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'expires_in'    => $socialUser->expiresIn,
                'data'          => $socialUser->getRaw(),
            ]
        );
    }

    /**
     * provider 帶進來的附屬資料超過欄位長度時截斷而不是擋下使用者——名字太長不該讓人登不進來。
     * varchar 算的是字元數，用 mb_substr 才不會把多位元組字元切壞。
     */
    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
