<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Query;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'range',
    description: 'Date range filter',
    in: 'query',
    required: false,
    schema: new OAT\Schema(
        type: 'string',
        enum: ['today', 'week', 'month', 'year']
    )
)]
class Range
{
}
