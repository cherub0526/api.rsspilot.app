<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Source;
use Hypervel\Http\Resources\Json\JsonResource;

class PopularSourceResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [
            'id'               => strval($this->resource->id),
            'type'             => $this->resource->type === Source::TYPE_YOUTUBE_CHANNEL ? 'channel' : 'playlist',
            'name'             => strval($this->resource->title ?? ''),
            'desc'             => strval($this->resource->description ?? ''),
            'thumbnail'        => $this->resource->thumbnail,
            'video_count'      => $this->resource->media_count ?? 0,
            'subscriber_count' => $this->resource->type === Source::TYPE_YOUTUBE_CHANNEL
                ? $this->formatSubscriberCount($this->resource->metadata['subscriber_count'] ?? null)
                : null,
        ];
    }

    private function formatSubscriberCount(?int $count): ?string
    {
        if ($count === null) {
            return null;
        }
        if ($count >= 1_000_000) {
            return number_format($count / 1_000_000, 1) . 'M';
        }
        if ($count >= 1_000) {
            return number_format($count / 1_000, 1) . 'K';
        }

        return (string) $count;
    }
}
