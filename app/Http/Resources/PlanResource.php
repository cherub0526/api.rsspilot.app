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
            'mindmap_limit' => intval($this->resource->mindmap_limit),
            'download_enabled'       => boolval($this->resource->download_enabled),
            'agent_enabled'          => boolval($this->resource->agent_enabled),
            'advanced_model_enabled' => boolval($this->resource->advanced_model_enabled),
            'custom_summary_enabled' => boolval($this->resource->custom_summary_enabled),
            'ai_quality'             => strval($this->resource->ai_quality),
            'prices' => PriceResource::collection($this->whenLoaded('prices')),
        ];
    }
}
