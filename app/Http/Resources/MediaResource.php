<?php

declare(strict_types=1);

namespace App\Http\Resources;

use OpenApi\Attributes as OAT;
use Hypervel\Http\Resources\Json\JsonResource;

#[OAT\Schema(
    schema: 'Media',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'url', type: 'string', example: 'https://www.youtube.com/embed/dQw4w9WgXcQ'),
        new OAT\Property(property: 'type', type: 'string', example: 'youtube'),
        new OAT\Property(property: 'title', type: 'string', example: 'Video Title'),
        new OAT\Property(property: 'description', type: 'string', example: 'Video description'),
        new OAT\Property(property: 'thumbnail', type: 'string', example: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'),
        new OAT\Property(property: 'published_at', type: 'string', format: 'date-time', example: '2024-01-01 12:00:00'),
        new OAT\Property(property: 'short_summary', type: 'string', example: 'A short summary of the video content.'),
    ]
)]
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
