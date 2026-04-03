<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Query;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'type',
    description: 'Type of media',
    in: 'query',
    required: true,
    schema: new OAT\Schema(
        type: 'string',
        enum: ['youtube', 'spotify']
    )
)]
class Type
{
}
