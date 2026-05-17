<?php

declare(strict_types=1);

namespace App\Validators;

class BaseValidator
{
    protected array $rules = [];

    protected array $messages = [];

    protected array $params = [];

    protected $validator;

    public function __construct($params)
    {
        $this->params = $params;
    }

    public function passes(): bool
    {
        $this->validator = validator($this->params, $this->rules, $this->messages);

        return !$this->validator->fails();
    }

    public function errors()
    {
        return $this->validator->errors();
    }

    public function validate()
    {
        return $this->validator;
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }
}
