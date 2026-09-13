<?php

declare(strict_types=1);

namespace App\Validators;

use App\Services\ThumbnailService;

class ThumbnailValidator extends BaseValidator
{
    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'file.required'   => __('validators.thumbnail.file.required'),
            'file.file'       => __('validators.thumbnail.file.file'),
            'file.mimetypes'  => __('validators.thumbnail.file.mimetypes'),
            'file.max'        => __('validators.thumbnail.file.max'),
            'second.required' => __('validators.thumbnail.second.required'),
            'second.integer'  => __('validators.thumbnail.second.integer'),
            'second.min'      => __('validators.thumbnail.second.min'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            // 檔案是前端 canvas 產生的 Blob，檔名可能只是 "blob" 而沒有副檔名，
            // 所以驗 mimetypes（看實際內容）而不是 mimes（看副檔名）。
            'file' => 'required|file|mimetypes:' . ThumbnailService::MIME_TYPE . '|max:2048',
            // 比對 media.duration 的上界屬於業務判斷，留在 Controller；這裡擋的是
            // 定址上界——超過 6 位的秒數 GET 的路由永遠匹配不到（見 MAX_SECOND）。
            'second' => 'required|integer|min:0|max:' . ThumbnailService::MAX_SECOND,
            // 路徑由 checksum 決定，所以格式要嚴格（小寫 hex、64 位）。值本身
            // 不採信，Controller 會以檔案內容重算比對。
            'checksum' => ['required', 'string', 'regex:' . ThumbnailService::CHECKSUM_REGEX],
        ];

        return $this;
    }
}
