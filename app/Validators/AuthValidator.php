<?php

declare(strict_types=1);

namespace App\Validators;

class AuthValidator extends BaseValidator
{
    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'email.required'                 => __('validators.auth.email.required'),
            'email.email'                    => __('validators.auth.email.email'),
            'email.max'                      => __('validators.auth.email.max'),
            'email.unique'                   => __('validators.auth.email.unique'),
            'code.required'                  => __('validators.auth.code.required'),
            'code.digits'                    => __('validators.auth.code.digits'),
            'token.required'                 => __('validators.auth.token.required'),
            'token.string'                   => __('validators.auth.token.string'),
            'password.required'              => __('validators.auth.password.required'),
            'password.string'                => __('validators.auth.password.string'),
            'password.min'                   => __('validators.auth.password.min'),
            'password.max'                   => __('validators.auth.password.max'),
            'password.regex'                 => __('validators.auth.password.regex'),
            'password.confirmed'             => __('validators.auth.password.confirmed'),
            'password_confirmation.required' => __('validators.auth.password_confirmation.required'),
            'password_confirmation.string'   => __('validators.auth.password_confirmation.string'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:8',
        ];

        return $this;
    }

    /**
     * unique:users,email 是唯一性的第一道；真正的最後防線是 users.email 的
     * 唯一索引（見 2026_09_03_100000 migration）——「先查再寫」在協程併發下
     * 會讓兩個請求雙雙通過這裡。
     */
    public function setRegisterRules(): self
    {
        $this->rules = [
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => [
                'required',
                'string',
                'min:8',
                'max:64',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*[^A-Za-z0-9]).+$/',
                'confirmed',
            ],
        ];

        return $this;
    }

    /** 手動輸入 6 位數碼。 */
    public function setVerifyRules(): self
    {
        $this->rules = [
            'email' => 'required|email|max:255',
            'code'  => 'required|digits:6',
        ];

        return $this;
    }

    /** 信件連結帶回來的一次性 token。 */
    public function setVerifyTokenRules(): self
    {
        $this->rules = [
            'token' => 'required|string|max:64',
        ];

        return $this;
    }

    public function setResendRules(): self
    {
        $this->rules = [
            'email' => 'required|email|max:255',
        ];

        return $this;
    }
}
