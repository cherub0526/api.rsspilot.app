<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id'            => strval($this->resource->id),
            'title'         => strval($this->resource->title),
            'description'   => strval($this->resource->description),
            'channel_limit' => intval($this->resource->channel_limit),
            'video_limit'   => intval($this->resource->video_limit),
            'chat_limit'    => intval($this->resource->chat_limit),
            'prices'        => PriceResource::collection($this->whenLoaded('prices')),
            'paddle'        => new PaddleResource($this->whenLoaded('paddle')),
        ];
    }
}
