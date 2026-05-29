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
            'created_at' => strval($this->resource->getAttribute('created_at')),
            'updated_at' => strval($this->resource->getAttribute('updated_at')),
        ];
    }
}
