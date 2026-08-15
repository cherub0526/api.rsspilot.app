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
            'url.required'  => __('validators.media.url.required'),
            'url.string'    => __('validators.media.url.string'),
        ];
    }

    public function setIndexRules(): self
    {
        $this->rules = [
            'type'    => 'required|string|in:' . implode(',', array_keys(Media::$typeMaps)),
            'limit'   => 'sometimes|integer|min:1|max:12',
            'keyword' => 'sometimes|nullable|string|max:255',
        ];

        return $this;
    }

    /**
     * url 只驗「有給、是字串」。是不是合法的 YouTube 影片網址交給
     * YoutubeService::getVideoIdFromUrl() 判斷——網址規則跟著平台走，
     * 塞進 validator 的 url 規則反而會把 youtu.be 這類形式擋掉。
     */
    public function setStoreRules(): self
    {
        $this->rules = [
            'url' => 'required|string',
        ];

        return $this;
    }
}
