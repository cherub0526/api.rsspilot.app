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
            // 主鍵是 ULID，本來就是字串；仍統一走 strval 與專案其他 Resource 一致。
            'id'         => strval($this->resource->id),
            'title'      => strval($this->resource->getAttribute('title') ?? ''),
            'content'    => strval($this->resource->getAttribute('content') ?? ''),
            'created_at' => $this->resource->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $this->resource->getAttribute('updated_at')?->toIso8601String(),
        ];
    }
}
