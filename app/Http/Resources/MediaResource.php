<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id'            => strval($this->resource->id),
            'url'           => strval('https://www.youtube.com/embed/' . $this->resource->video_detail['yt:videoId']),
            'type'          => strval($this->resource->type),
            'title'         => strval($this->resource->title),
            'description'   => strval($this->resource->description),
            'thumbnail'     => strval($this->resource->thumbnail),
            'published_at'  => strval($this->resource->published_at),
            'short_summary' => $this->resource->summary ? $this->resource->summary->text['short_summary'] : '',
            //            'author'       => $this->whenLoaded('rss', function () {
            //                return match ($this->resource->type) {
            //                    Media::TYPE_YOUTUBE => $this->youtube(),
            //                };
            //            }),
        ];
    }

    protected function youtube(): array
    {
        return [
            'name'   => $this->resource->rss->avatar,
            'avatar' => $this->resource->rss->avatar,
        ];
    }
}
