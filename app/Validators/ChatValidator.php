<?php

declare(strict_types=1);

namespace App\Validators;

use App\Services\ThumbnailService;

class ChatValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'session_id.regex'                      => __('validators.chat.session_id.invalid'),
            'messages.required'                     => __('validators.chat.messages.required'),
            'messages.array'                        => __('validators.chat.messages.array'),
            'messages.min'                          => __('validators.chat.messages.min'),
            'messages.*.role.required'              => __('validators.chat.messages.role.required'),
            'messages.*.role.string'                => __('validators.chat.messages.role.string'),
            'messages.*.role.in'                    => __('validators.chat.messages.role.in'),
            'messages.*.content.required'           => __('validators.chat.messages.content.required'),
            'messages.*.content.string'             => __('validators.chat.messages.content.string'),
            'messages.*.images.array'               => __('validators.chat.messages.images.array'),
            'messages.*.images.max'                 => __('validators.chat.messages.images.max'),
            'messages.*.images.*.second.required'   => __('validators.chat.messages.images.second.required'),
            'messages.*.images.*.second.integer'    => __('validators.chat.messages.images.second.integer'),
            'messages.*.images.*.second.min'        => __('validators.chat.messages.images.second.min'),
            'messages.*.images.*.second.max'        => __('validators.chat.messages.images.second.max'),
            'messages.*.images.*.checksum.required' => __('validators.chat.messages.images.checksum.required'),
            'messages.*.images.*.checksum.string'   => __('validators.chat.messages.images.checksum.string'),
            'messages.*.images.*.checksum.regex'    => __('validators.chat.messages.images.checksum.regex'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'session_id'         => ['nullable', 'string', 'regex:/^[0-7][0-9a-hjkmnp-tv-z]{25}$/'],
            'messages'           => 'required|array|min:1',
            'messages.*.role'    => 'required|string|in:user,assistant,system',
            'messages.*.content' => 'required|string',
            // 附在該回合的截圖。圖片本身先由 /thumbnails 端點上傳，這裡只帶它的
            // 身分：second 是位置、checksum 才是識別（同一秒有 24–60 幀）。
            // 上限 4 張是成本考量——每張圖都會被算進推論的 token，一次塞十幾張
            // 畫面對回答品質也沒有幫助。
            'messages.*.images'            => 'sometimes|array|max:4',
            'messages.*.images.*.second'   => 'required|integer|min:0|max:' . ThumbnailService::MAX_SECOND,
            'messages.*.images.*.checksum' => ['required', 'string', 'regex:' . ThumbnailService::CHECKSUM_REGEX],
        ];

        return $this;
    }
}
