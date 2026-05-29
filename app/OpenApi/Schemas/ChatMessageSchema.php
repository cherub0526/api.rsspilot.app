<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'ChatMessage',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'role', type: 'string', enum: ['user', 'ai'], example: 'user'),
        new OAT\Property(property: 'content', type: 'string', example: 'What is this video about?'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-29T10:00:00Z'),
    ],
    type: 'object'
)]
class ChatMessageSchema
{
}
