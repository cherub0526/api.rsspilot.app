<?php

declare(strict_types=1);

namespace App\Validators;

class ForgotPasswordValidator extends BaseValidator
{
    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'account.required'   => __('validators.auth.account.required'),
            'account.string'     => __('validators.auth.account.string'),
            'account.min'        => __('validators.auth.account.min'),
            'account.max'        => __('validators.auth.account.max'),
            'expires.required'   => __('validators.auth.expires.required'),
            'expires.integer'    => __('validators.auth.expires.integer'),
            'id.required'        => __('validators.auth.id.required'),
            'id.string'          => __('validators.auth.id.string'),
            'token.required'     => __('validators.auth.token.required'),
            'token.string'       => __('validators.auth.token.string'),
            'signature.required' => __('validators.auth.signature.required'),
            'signature.string'   => __('validators.auth.signature.string'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'account' => 'required|string|min:6|max:255',
        ];

        return $this;
    }

    public function setUpdateRules(): self
    {
        $this->rules = [
            'expires'   => 'required|integer',
            'id'        => 'required|string',
            'token'     => 'required|string',
            'signature' => 'required|string',
            'password'  => 'required|min:8|confirmed',
        ];

        return $this;
    }
}
