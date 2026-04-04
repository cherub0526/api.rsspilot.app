<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'SummaryResource',
    properties: [
        new OAT\Property(property: 'locale', type: 'string', example: 'en'),
        new OAT\Property(property: 'status', type: 'string', example: 'ready'),
        new OAT\Property(property: 'text', type: 'object'),
    ],
    type: 'object'
)]
class SummaryResource
{
}
