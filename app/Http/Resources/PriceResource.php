<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class PriceResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id'     => strval($this->resource->id),
            'unit'   => strval($this->resource->unit),
            'price'  => floatval($this->resource->price),
            'paddle' => new PaddleResource($this->whenLoaded('paddle')),
        ];
    }
}
