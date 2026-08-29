<?php

declare(strict_types=1);

namespace App\Validators;

use App\Models\Oauth;

class OauthValidator extends BaseValidator
{
    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'provider.required' => __('validators.oauth.provider.required'),
            'provider.in'       => __('validators.oauth.provider.in'),
            'code.required'     => __('validators.oauth.code.required'),
            'redirect.required' => __('validators.oauth.redirect.required'),
            'redirect.string'   => __('validators.oauth.redirect.string'),
            'redirect.url'      => __('validators.oauth.redirect.url'),
            'redirect.max'      => __('validators.oauth.redirect.max'),
        ];
    }

    /**
     * 收 provider callback、拿 code 換 token 用。
     *
     * `redirect` 不是多餘的——provider 會核對交換 token 時的 redirect_uri 與當初
     * 發出授權請求時的是否逐字相同，所以呼叫端必須把同一個值再送一次。
     * `provider` 來自 URL 路徑段而不是 body，呼叫端要自己併進參數陣列。
     */
    public function setStoreRules(): self
    {
        $this->rules = [
            'provider' => 'required|in:' . implode(',', array_keys(Oauth::$providerMaps)),
            'code'     => 'required',
            'redirect' => 'required|string|url|max:255',
        ];

        return $this;
    }

    /**
     * 產生 provider 授權連結用。
     *
     * `provider` 來自 URL 路徑段而不是 body，呼叫端要自己併進參數陣列。
     */
    public function setRedirectRules(): self
    {
        $this->rules = [
            'provider' => 'required|in:' . implode(',', array_keys(Oauth::$providerMaps)),
            'redirect' => 'required|string|url|max:255',
        ];

        return $this;
    }
}
