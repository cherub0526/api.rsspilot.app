<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'PriceResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'unit', type: 'string', example: 'month'),
        new OAT\Property(property: 'price', type: 'number', format: 'float', example: 9.99),
        new OAT\Property(
            property: 'first_period_price',
            type: 'number',
            format: 'float',
            nullable: true,
            example: 99,
            description: 'Discounted first-period price (annual only); null when there is no intro offer. Renewals always charge `price`.'
        ),
        new OAT\Property(property: 'paddle', ref: PaddleResource::class),
    ],
    type: 'object'
)]
class PriceResource
{
}
