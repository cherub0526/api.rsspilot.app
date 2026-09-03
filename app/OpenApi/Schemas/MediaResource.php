<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'MediaResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'url', type: 'string', example: 'https://www.youtube.com/embed/dQw4w9WgXcQ'),
        new OAT\Property(property: 'type', type: 'string', example: 'youtube'),
        new OAT\Property(property: 'title', type: 'string', example: 'Video Title'),
        new OAT\Property(
            property: 'status',
            type: 'string',
            description: '處理進度，見 App\\Models\\Media 的 STATUS_* 常數',
            enum: [
                'created', 'progress', 'transcribing', 'transcribed', 'transcribe_failed',
                'summarizing', 'summarized', 'summarize_failed', 'ready', 'cancelled', 'failed',
            ],
            example: 'ready'
        ),
        new OAT\Property(property: 'description', type: 'string', example: 'Video description'),
        new OAT\Property(property: 'thumbnail', type: 'string', example: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'),
        new OAT\Property(property: 'published_at', type: 'string', format: 'date-time', example: '2024-01-01 12:00:00'),
        new OAT\Property(property: 'short_summary', type: 'string', example: 'A short summary of the video content.'),
        new OAT\Property(property: 'source', ref: SourceResource::class, nullable: true),
    ],
    type: 'object'
)]
class MediaResource
{
}
