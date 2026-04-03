<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Query;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'limit',
    description: 'Number of items per page (1-10, default 12)',
    in: 'query',
    required: false,
    schema: new OAT\Schema(
        type: 'integer',
        maximum: 10,
        minimum: 1
    )
)]
class Limit
{
}
