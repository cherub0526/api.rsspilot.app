<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plan;
use App\Enums\Feature;
use Hypervel\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * skill_enabled 是「實際給不給」：全站關掉頻道 Skill 時那些端點是 404，
     * 不能讓定價頁把它列成權益（config/features.php）。
     */
    public function toArray(): array
    {
        return [
            'id'                     => strval($this->resource->id),
            'title'                  => strval($this->resource->title),
            'description'            => strval($this->resource->description),
            'channel_limit'          => intval($this->resource->channel_limit),
            'video_limit'            => intval($this->resource->video_limit),
            'chat_limit'             => intval($this->resource->chat_limit),
            'mindmap_limit'          => intval($this->resource->mindmap_limit),
            'download_enabled'       => boolval($this->resource->download_enabled),
            'agent_enabled'          => boolval($this->resource->agent_enabled),
            'thinking_enabled'       => boolval($this->resource->thinking_enabled),
            'mcp_enabled'            => boolval($this->resource->mcp_enabled),
            'skill_enabled'          => Feature::ChannelSkill->enabled() && boolval($this->resource->skill_enabled),
            'advanced_model_enabled' => boolval($this->resource->advanced_model_enabled),
            'custom_summary_enabled' => boolval($this->resource->custom_summary_enabled),
            'screenshot_enabled'     => boolval($this->resource->screenshot_enabled),
            'ai_quality'             => strval($this->resource->ai_quality),
            // 隱藏方案的連結碼（access_code）永遠不輸出，只告訴前端「這是專屬方案」
            'hidden' => $this->resource->visibility === Plan::VISIBILITY_HIDDEN,
            'prices' => PriceResource::collection($this->whenLoaded('prices')),
        ];
    }
}
