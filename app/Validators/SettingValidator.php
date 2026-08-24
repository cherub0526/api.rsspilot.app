<?php

declare(strict_types=1);

namespace App\Validators;

use App\Utils\Const\ISO6391;

class SettingValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'ai.required_without'       => __('validators.settings.ai.required'),
            'ai.language.required_with' => __('validators.settings.ai.language.required'),
            'ai.language.in'            => __('validators.settings.ai.language.in'),
            'locale.required_without'   => __('validators.settings.locale.required'),
            'locale.in'                 => __('validators.settings.locale.in'),
        ];
    }

    public function setUpdateRules(): self
    {
        $this->rules = [
            'ai'          => 'required_without:locale|array',
            'ai.language' => 'required_with:ai|in:' . implode(',', array_values(ISO6391::LANGUAGES)),
            'locale'      => 'required_without:ai|in:' . implode(',', config('app.available_locales')),
        ];

        return $this;
    }
}
