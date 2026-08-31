<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [
            'id'      => strval($this->resource->id),
            'role'    => strval($this->resource->getAttribute('role')),
            'content' => strval($this->resource->getAttribute('content')),
            // parts 是結構化的真相，content 是它的純文字投影。兩者都輸出：
            // 既有呼叫端讀 content 不受影響，新的呼叫端讀 parts 才拿得到
            // 思考過程與工具呼叫。parts 對舊資料列也一定有值（見 contentParts）。
            'parts'      => $this->resource->contentParts(),
            'created_at' => $this->resource->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
