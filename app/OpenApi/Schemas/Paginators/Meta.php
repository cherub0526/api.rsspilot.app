<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Paginators;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'PaginatorMeta',
    properties: [
        new OAT\Property(property: 'current_page', type: 'integer', example: 1),
        new OAT\Property(property: 'per_page', type: 'integer', example: 12),
        new OAT\Property(property: 'total', type: 'integer', example: 50),
    ],
    type: 'object'
)]
class Meta
{
}
