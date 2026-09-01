<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'CustomPromptResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01k9v7m2q8n4r6t0w3y5z7b1c9'),
        new OAT\Property(property: 'title', type: 'string', example: '學習筆記摘要'),
        new OAT\Property(property: 'content', type: 'string', example: '請以學習筆記的風格整理這部影片的重點…'),
        new OAT\Property(
            property: 'model',
            description: 'Only present when eager loaded. Null when the config has no model pinned.',
            nullable: true,
            ref: AiModelResource::class
        ),
        new OAT\Property(
            property: 'sources',
            type: 'array',
            description: 'Channels / playlists this config applies to. Only present when eager loaded.',
            items: new OAT\Items(ref: SourceResource::class)
        ),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object'
)]
class CustomPromptResource
{
}
