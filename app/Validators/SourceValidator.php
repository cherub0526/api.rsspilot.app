<?php

declare(strict_types=1);

namespace App\Validators;

class SourceValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'url.required'    => __('validators.source.url.required'),
            'type.required'   => __('validators.source.type.required'),
            'type.in'         => __('validators.source.type.in'),
            'notify.required' => __('validators.source.notify.required'),
            'notify.boolean'  => __('validators.source.notify.boolean'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'url'    => 'required|string',
            'type'   => 'required|string|in:channel,playlist',
            'notify' => 'sometimes|boolean',
        ];

        return $this;
    }

    public function setUpdateRules(): self
    {
        $this->rules = [
            'notify' => 'required|boolean',
        ];

        return $this;
    }
}
