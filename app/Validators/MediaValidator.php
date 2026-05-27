<?php

declare(strict_types=1);

namespace App\Validators;

use App\Models\Media;

class MediaValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'type.required' => __('validators.media.type.required'),
            'type.string'   => __('validators.media.type.string'),
            'type.in'       => __('validators.media.type.in'),
            'limit.integer' => __('validators.media.limit.integer'),
            'limit.min'     => __('validators.media.limit.min'),
            'limit.max'     => __('validators.media.limit.max'),
        ];
    }

    public function setIndexRules(): self
    {
        $this->rules = [
            'type'    => 'required|string|in:' . implode(',', array_keys(Media::$typeMaps)),
            'limit'   => 'sometimes|integer|min:1|max:10',
            'keyword' => 'sometimes|nullable|string|max:255',
        ];

        return $this;
    }
}
