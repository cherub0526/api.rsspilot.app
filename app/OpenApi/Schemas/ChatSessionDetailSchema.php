<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'ChatSessionDetail',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'title', type: 'string', nullable: true, example: 'What is this video about?'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-29T10:00:00Z'),
        new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-05-29T10:05:00Z'),
        new OAT\Property(
            property: 'messages',
            type: 'array',
            items: new OAT\Items(ref: ChatMessageSchema::class)
        ),
    ],
    type: 'object'
)]
class ChatSessionDetailSchema
{
}
