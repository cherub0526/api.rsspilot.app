<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'AiModelResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01k9v7m2q8n4r6t0w3y5z7b1c9'),
        new OAT\Property(property: 'name', type: 'string', example: 'Claude 3.5 Sonnet'),
        new OAT\Property(property: 'supports_thinking', type: 'boolean', example: true),
        new OAT\Property(
            property: 'min_plan',
            description: 'Lowest plan that includes this model, derived from plan_ai_models. '
                . 'Null when the plans relation was not eager loaded, or no plan includes it.',
            nullable: true,
            properties: [
                new OAT\Property(property: 'id', type: 'string', example: '01k9v7m2q8n4r6t0w3y5z7b1c9'),
                new OAT\Property(property: 'title', type: 'string', example: 'Pro'),
                new OAT\Property(property: 'sort', type: 'integer', example: 1),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
class AiModelResource
{
}
