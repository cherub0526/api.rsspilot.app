<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * 使用者收藏的 prompt 設定（custom_prompts 資料表）。
 *
 * 與單數的 CustomPromptValidator 是兩回事：那一支驗的是
 * POST /v1/media/{mediaId}/custom-prompt 當下要跑的 prompt 字串，不落地。
 */
class CustomPromptsValidator extends BaseValidator
{
    /**
     * content 欄位是 TEXT（65535 bytes）。max 算的是字元數，中文一字三 bytes，
     * 取 20000 是留足餘裕又不會讓合理長度的 prompt 被擋下。
     */
    private const CONTENT_MAX_LENGTH = 20000;

    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'title.required'   => __('validators.custom_prompts.title.required'),
            'title.string'     => __('validators.custom_prompts.title.string'),
            'title.max'        => __('validators.custom_prompts.title.max'),
            'content.required' => __('validators.custom_prompts.content.required'),
            'content.string'   => __('validators.custom_prompts.content.string'),
            'content.max'      => __('validators.custom_prompts.content.max'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            // title 是 varchar(255)：使用者自己打的內容，超長就擋下而不是截斷。
            'title'   => 'required|string|max:255',
            'content' => 'required|string|max:' . self::CONTENT_MAX_LENGTH,
        ];

        return $this;
    }

    /**
     * PUT 是整筆取代而不是局部更新，兩個欄位都必填，規則與新增相同。
     * 仍然分成兩個方法：日後兩者要分頭調整時不必先拆。
     */
    public function setUpdateRules(): self
    {
        $this->rules = [
            'title'   => 'required|string|max:255',
            'content' => 'required|string|max:' . self::CONTENT_MAX_LENGTH,
        ];

        return $this;
    }
}
