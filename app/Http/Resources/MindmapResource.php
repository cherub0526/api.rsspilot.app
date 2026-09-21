<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class MindmapResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return [
            'language' => strval($this->resource->language),
            'status'   => strval($this->resource->status),
            'markdown' => $this->resource->markdown,
        ];
    }
}
