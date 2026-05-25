<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Source;
use Hypervel\Http\Resources\Json\JsonResource;

class SourceDetailResource extends JsonResource
{
    public ?string $wrap = null;

    private function resolveDisplayUrl(): string
    {
        if ($this->resource->type === Source::TYPE_YOUTUBE_CHANNEL) {
            return 'https://www.youtube.com/channel/' . $this->resource->external_id;
        }

        return 'https://www.youtube.com/playlist?list=' . $this->resource->external_id;
    }

    /**
     * subscriber_count 僅對頻道有意義；播放清單來源一律回傳 null。
     * 頻道若 metadata 中不存在該欄位，退回整數 0。
     */
    private function resolveSubscriberCount(): ?int
    {
        if ($this->resource->type !== Source::TYPE_YOUTUBE_CHANNEL) {
            return null;
        }

        return (int) ($this->resource->metadata['subscriber_count'] ?? 0);
    }

    public function toArray(): array
    {
        return [
            'id'               => strval($this->resource->id),
            'name'             => strval($this->resource->title ?? ''),
            'url'              => $this->resolveDisplayUrl(),
            'type'             => $this->resource->type === Source::TYPE_YOUTUBE_CHANNEL ? 'channel' : 'playlist',
            'notify'           => (bool) ($this->resource->pivot?->notify ?? true),
            'thumbnail'        => $this->resource->thumbnail,
            'description'      => strval($this->resource->description ?? ''),
            'subscriber_count' => $this->resolveSubscriberCount(),
        ];
    }
}
