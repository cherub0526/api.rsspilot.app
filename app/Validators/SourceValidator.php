<?php

declare(strict_types=1);

namespace App\Validators;

class SourceValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'url.required'      => __('validators.source.url.required'),
            'type.required'     => __('validators.source.type.required'),
            'type.in'           => __('validators.source.type.in'),
            'notify.required'   => __('validators.source.notify.required'),
            'notify.boolean'    => __('validators.source.notify.boolean'),
            'video_ids.array'   => __('validators.source.video_ids.array'),
            'video_ids.max'     => __('validators.source.video_ids.max'),
            'video_ids.*.regex' => __('validators.source.video_ids.regex'),
        ];
    }

    public function setPreviewRules(): self
    {
        $this->rules = [
            'url'  => 'required|string',
            'type' => 'required|string|in:channel,playlist',
        ];

        return $this;
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'url'         => 'required|string',
            'type'        => 'required|string|in:channel,playlist',
            'notify'      => 'sometimes|boolean',
            'video_ids'   => 'sometimes|array|max:50',
            'video_ids.*' => ['string', 'regex:/^[A-Za-z0-9_-]{11}$/'],
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
