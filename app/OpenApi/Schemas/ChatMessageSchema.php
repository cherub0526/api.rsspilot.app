<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'ChatMessage',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'role', type: 'string', enum: ['user', 'ai'], example: 'user'),
        new OAT\Property(
            property: 'content',
            type: 'string',
            description: 'Plain-text projection of the text parts.',
            example: 'What is this video about?'
        ),
        new OAT\Property(
            property: 'parts',
            type: 'array',
            description: 'Ordered parts that make up this turn. Always present; messages stored '
                . 'before parts existed are returned as a single text part.',
            items: new OAT\Items(
                properties: [
                    new OAT\Property(
                        property: 'type',
                        type: 'string',
                        enum: ['text', 'thinking', 'tool_call', 'tool_result'],
                        example: 'text'
                    ),
                    new OAT\Property(property: 'text', type: 'string', nullable: true, description: 'text / thinking'),
                    new OAT\Property(property: 'id', type: 'string', nullable: true, description: 'tool_call'),
                    new OAT\Property(property: 'name', type: 'string', nullable: true, description: 'tool_call'),
                    new OAT\Property(property: 'input', type: 'object', nullable: true, description: 'tool_call'),
                    new OAT\Property(property: 'tool_call_id', type: 'string', nullable: true, description: 'tool_result'),
                    new OAT\Property(property: 'output', type: 'string', nullable: true, description: 'tool_result'),
                    new OAT\Property(property: 'is_error', type: 'boolean', nullable: true, description: 'tool_result'),
                ],
                type: 'object'
            )
        ),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-29T10:00:00Z'),
    ],
    type: 'object'
)]
class ChatMessageSchema
{
}
