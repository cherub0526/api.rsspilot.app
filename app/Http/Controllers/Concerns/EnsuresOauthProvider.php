<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Exceptions\InvalidRequestException;

/**
 * OAuth 流程兩端（產生授權網址、收 callback）共用的 provider 檢查。
 *
 * provider 在 `Oauth::$providerMaps` 裡，不代表它的 credentials 已經設好——
 * 少了 client_id 時 SocialiteManager 會在建構 driver 的當下就炸，那是 500，
 * 但成因其實是「這個環境沒開通這個登入方式」，屬於呼叫端該被告知的狀況。
 * 兩支端點必須給出同一個答案，所以判斷放在這裡而不是各自複製一份。
 */
trait EnsuresOauthProvider
{
    /**
     * @throws InvalidRequestException
     */
    protected function ensureProviderIsConfigured(string $provider): void
    {
        if (config("services.{$provider}.client_id")) {
            return;
        }

        throw new InvalidRequestException(
            ['provider' => [__('validators.controllers.oauth.provider_not_configured')]]
        );
    }
}
