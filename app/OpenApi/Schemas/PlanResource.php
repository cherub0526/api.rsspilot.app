<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'PlanResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'title', type: 'string', example: 'Pro Plan'),
        new OAT\Property(property: 'description', type: 'string', example: 'Full access to all features'),
        new OAT\Property(property: 'channel_limit', type: 'integer', example: 10),
        new OAT\Property(property: 'video_limit', type: 'integer', example: 100),
        new OAT\Property(
            property: 'chat_limit',
            type: 'integer',
            description: 'Daily AI chat question limit. 0 means unlimited.',
            example: 50
        ),
        new OAT\Property(
            property: 'mindmap_limit',
            type: 'integer',
            description: 'Daily mind map generation limit. 0 means unlimited.',
            example: 50
        ),
        new OAT\Property(property: 'download_enabled', type: 'boolean', example: true),
        new OAT\Property(property: 'agent_enabled', type: 'boolean', example: false),
        new OAT\Property(property: 'advanced_model_enabled', type: 'boolean', example: true),
        new OAT\Property(property: 'custom_summary_enabled', type: 'boolean', example: true),
        new OAT\Property(property: 'ai_quality', type: 'string', enum: ['pro', 'advanced', 'deep'], example: 'advanced'),
        new OAT\Property(
            property: 'prices',
            type: 'array',
            items: new OAT\Items(ref: PriceResource::class)
        ),
        new OAT\Property(property: 'paddle', ref: PaddleResource::class),
    ],
    type: 'object'
)]
class PlanResource
{
}
