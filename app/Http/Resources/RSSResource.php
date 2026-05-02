<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class RSSResource extends JsonResource
{
    public ?string $wrap = null;

    private function resolveYoutubeUrl(): string
    {
        $listId = $this->resource->list_id;

        if (!$listId) {
            return strval($this->resource->url);
        }

        if (str_contains($this->resource->url, 'playlist_id=')) {
            return 'https://www.youtube.com/playlist?list=' . $listId;
        }

        return 'https://www.youtube.com/channel/' . $listId;
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id'         => strval($this->resource->id),
            'type'       => strval($this->resource->type),
            'list_id'    => $this->resource->list_id ? strval($this->resource->list_id) : null,
            'url'        => $this->resolveYoutubeUrl(),
            'title'      => strval($this->resource->title),
            'created_at' => strval($this->resource->created_at),
        ];
    }
}
