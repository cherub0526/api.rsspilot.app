<?php

declare(strict_types=1);

namespace App\Validators;

class AvatarValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'file' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:2048',
        ];

        return $this;
    }
}
