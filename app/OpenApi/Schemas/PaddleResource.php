<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'PaddleResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: 'pro_01abc123'),
    ],
    type: 'object'
)]
class PaddleResource
{
}
