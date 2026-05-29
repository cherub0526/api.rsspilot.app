<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class UserChatSessionResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        $messages = $this->resource->messages;
        $lastMessages = $messages->slice(max(0, $messages->count() - 2))->values();

        return [
            'id'            => strval($this->resource->id),
            'title'         => strval($this->resource->getAttribute('title') ?? ''),
            'created_at'    => $this->resource->getAttribute('created_at')?->toIso8601String(),
            'updated_at'    => $this->resource->getAttribute('updated_at')?->toIso8601String(),
            'message_count' => (int) $this->resource->messages_count,
            'media'         => $this->whenLoaded('media', fn () => new MediaResource($this->resource->media)),
            'last_messages' => ChatMessageResource::collection($lastMessages),
        ];
    }
}
