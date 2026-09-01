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

    /**
     * 一份設定最多套用幾個來源。上限取自方案能訂閱的頻道數的寬鬆倍數——
     * 沒有這個上限的話，一次送幾千個 id 就會讓 sync() 掃全表。
     */
    private const SOURCE_IDS_MAX = 100;

    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'title.required'    => __('validators.custom_prompts.title.required'),
            'title.string'      => __('validators.custom_prompts.title.string'),
            'title.max'         => __('validators.custom_prompts.title.max'),
            'content.required'  => __('validators.custom_prompts.content.required'),
            'content.string'    => __('validators.custom_prompts.content.string'),
            'content.max'       => __('validators.custom_prompts.content.max'),
            'media_id.required' => __('validators.custom_prompts.media_id.required'),
            'media_id.size'     => __('validators.custom_prompts.media_id.size'),
            'model_id.size'     => __('validators.custom_prompts.model_id.size'),
            'source_ids.array'  => __('validators.custom_prompts.source_ids.array'),
            'source_ids.max'    => __('validators.custom_prompts.source_ids.max'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = self::baseRules();

        return $this;
    }

    /**
     * PUT 是整筆取代而不是局部更新，兩個欄位都必填，規則與新增相同。
     * 仍然分成兩個方法：日後兩者要分頭調整時不必先拆。
     */
    public function setUpdateRules(): self
    {
        $this->rules = self::baseRules();

        return $this;
    }

    /**
     * 試跑一份設定用。content 與儲存時同一組規則——試得動的內容就該存得下來，
     * 兩邊長度上限不同只會讓人在儲存那一步才發現不行。
     */
    public function setPreviewRules(): self
    {
        $this->rules = [
            'media_id' => 'required|string|size:26',
            'content'  => 'required|string|max:' . self::CONTENT_MAX_LENGTH,
            'model_id' => 'sometimes|nullable|string|size:26',
        ];

        return $this;
    }

    /**
     * 新增與更新的規則相同（PUT 是整筆取代），集中一份免得改一邊漏一邊。
     *
     * model_id 與 source_ids 都是 sometimes：沒送就是不指定模型、不套用任何來源，
     * 而不是「保持原狀」——PUT 的語意是整筆取代。
     *
     * 兩者的存在性不在這裡驗（Validator 不查 DB，見 .claude/rules/validators.md），
     * 由 Controller 以「屬於這個使用者」為條件查，查不到就當作沒有。
     *
     * @return array<string, string>
     */
    private static function baseRules(): array
    {
        return [
            // title 是 varchar(255)：使用者自己打的內容，超長就擋下而不是截斷。
            'title'        => 'required|string|max:255',
            'content'      => 'required|string|max:' . self::CONTENT_MAX_LENGTH,
            'model_id'     => 'sometimes|nullable|string|size:26',
            'source_ids'   => 'sometimes|array|max:' . self::SOURCE_IDS_MAX,
            'source_ids.*' => 'string|size:26',
        ];
    }
}
