<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class ChatSessionResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [
            'id'         => strval($this->resource->id),
            'title'      => strval($this->resource->getAttribute('title') ?? ''),
            'created_at' => $this->resource->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $this->resource->getAttribute('updated_at')?->toIso8601String(),
        ];
    }
}
