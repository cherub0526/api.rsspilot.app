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
                        enum: ['text', 'thinking', 'tool_call', 'tool_result', 'image'],
                        example: 'text'
                    ),
                    new OAT\Property(property: 'text', type: 'string', nullable: true, description: 'text / thinking'),
                    new OAT\Property(property: 'id', type: 'string', nullable: true, description: 'tool_call'),
                    new OAT\Property(property: 'name', type: 'string', nullable: true, description: 'tool_call'),
                    new OAT\Property(property: 'input', type: 'object', nullable: true, description: 'tool_call'),
                    new OAT\Property(property: 'tool_call_id', type: 'string', nullable: true, description: 'tool_result'),
                    new OAT\Property(property: 'output', type: 'string', nullable: true, description: 'tool_result'),
                    new OAT\Property(property: 'is_error', type: 'boolean', nullable: true, description: 'tool_result'),
                    new OAT\Property(property: 'second', type: 'integer', nullable: true, description: 'image', example: 125),
                    new OAT\Property(
                        property: 'checksum',
                        type: 'string',
                        nullable: true,
                        description: 'image — SHA-256 of the frame; this is what identifies it',
                        example: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
                    ),
                    new OAT\Property(
                        property: 'url',
                        type: 'string',
                        nullable: true,
                        description: 'image — pre-signed, valid 24 hours. Re-fetch the session instead of storing it.',
                        example: 'https://bucket.s3.amazonaws.com/media/01JCXYZ.../thumbnails/000125.e3b0c442....jpg?X-Amz-Signature=...'
                    ),
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
