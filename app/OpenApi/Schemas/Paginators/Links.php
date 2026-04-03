<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Paginators;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'PaginatorLinks',
    properties: [
        new OAT\Property(
            property: 'first',
            type: 'string',
            example: 'http://localhost/api/v1/media?page=1'
        ),
        new OAT\Property(
            property: 'last',
            type: 'string',
            example: 'http://localhost/api/v1/media?page=5'
        ),
        new OAT\Property(property: 'prev', type: 'string', example: null, nullable: true),
        new OAT\Property(
            property: 'next',
            type: 'string',
            example: 'http://localhost/api/v1/media?page=2',
            nullable: true
        ),
    ],
    type: 'object'
)]
class Links
{
}
