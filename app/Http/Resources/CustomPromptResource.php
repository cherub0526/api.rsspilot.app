<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class CustomPromptResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [
            // 主鍵是 bigIncrements，但沿用專案其他 Resource 的做法統一轉字串輸出，
            // 免得呼叫端在 number 與 string 之間各自猜。
            'id'         => strval($this->resource->id),
            'title'      => strval($this->resource->getAttribute('title') ?? ''),
            'content'    => strval($this->resource->getAttribute('content') ?? ''),
            'created_at' => $this->resource->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $this->resource->getAttribute('updated_at')?->toIso8601String(),
        ];
    }
}
