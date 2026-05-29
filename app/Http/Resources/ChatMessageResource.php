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
            'id'         => strval($this->resource->id),
            'role'       => strval($this->resource->getAttribute('role')),
            'content'    => strval($this->resource->getAttribute('content')),
            'created_at' => $this->resource->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
