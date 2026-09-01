<?php

declare(strict_types=1);

namespace App\Validators;

class AuthValidator extends BaseValidator
{
    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'account.required'               => __('validators.auth.account.required'),
            'account.string'                 => __('validators.auth.account.string'),
            'account.min'                    => __('validators.auth.account.min'),
            'account.max'                    => __('validators.auth.account.max'),
            'account.unique'                 => __('validators.auth.account.unique'),
            'email.required'                 => __('validators.auth.email.required'),
            'email.email'                    => __('validators.auth.email.email'),
            'email.max'                      => __('validators.auth.email.max'),
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
            'account'  => 'required|string|min:6|max:255',
            'password' => 'required|string|min:8',
        ];

        return $this;
    }

    public function setRegisterRules(): self
    {
        $this->rules = [
            'account'  => 'required|string|min:6|max:255|unique:users,account',
            'email'    => 'required|email|max:255',
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
}
