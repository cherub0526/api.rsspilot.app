<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'ChatSession',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'title', type: 'string', example: 'What is this video about?'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-29T10:00:00Z'),
        new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-05-29T10:05:00Z'),
    ],
    type: 'object'
)]
class ChatSessionSchema
{
}
