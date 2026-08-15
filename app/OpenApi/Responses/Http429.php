<?php

declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OAT;

#[OAT\Response(
    response: 429,
    description: 'Daily AI chat quota exhausted for the current plan',
    headers: [
        new OAT\Header(
            header: 'X-RateLimit-Limit',
            description: 'Daily question limit of the current plan',
            schema: new OAT\Schema(type: 'integer', example: 3)
        ),
        new OAT\Header(
            header: 'X-RateLimit-Remaining',
            description: 'Questions left today',
            schema: new OAT\Schema(type: 'integer', example: 0)
        ),
        new OAT\Header(
            header: 'X-RateLimit-Reset',
            description: 'Unix timestamp of the next quota reset (next 00:00 in the quota timezone)',
            schema: new OAT\Schema(type: 'integer', example: 1786752000)
        ),
    ],
    content: new OAT\JsonContent(
        properties: [
            new OAT\Property(
                property: 'messages',
                type: 'object',
                example: ['chat' => ['You have reached the daily AI chat limit for your plan.']]
            ),
        ]
    )
)]
class Http429
{
}
