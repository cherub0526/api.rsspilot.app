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
            // 上界要比對 media.duration，屬於業務判斷，留在 Controller。
            'second' => 'required|integer|min:0',
        ];

        return $this;
    }
}
