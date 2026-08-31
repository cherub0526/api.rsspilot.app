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
            // 關聯欄位一律排在本體欄位之後（見 .claude/rules/resources.md）。
            // 未 eager load 時這兩個 key 會整個消失，呼叫端不能假設它們一定存在。
            'model'   => $this->whenLoaded('model', fn () => new AiModelResource($this->resource->model)),
            'sources' => SourceResource::collection($this->whenLoaded('sources')),
        ];
    }
}
