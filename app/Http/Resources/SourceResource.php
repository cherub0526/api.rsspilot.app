<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Source;
use Hypervel\Http\Resources\Json\JsonResource;

class SourceResource extends JsonResource
{
    public ?string $wrap = null;

    private function resolveDisplayUrl(): string
    {
        if ($this->resource->type === Source::TYPE_YOUTUBE_CHANNEL) {
            return 'https://www.youtube.com/channel/' . $this->resource->external_id;
        }

        return 'https://www.youtube.com/playlist?list=' . $this->resource->external_id;
    }

    public function toArray(): array
    {
        return [
            'id'     => strval($this->resource->id),
            'name'   => strval($this->resource->title ?? ''),
            'url'    => $this->resolveDisplayUrl(),
            'type'   => $this->resource->type === Source::TYPE_YOUTUBE_CHANNEL ? 'channel' : 'playlist',
            'notify' => (bool) ($this->resource->pivot?->notify ?? true),
        ];
    }
}
