<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'CaptionResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'locale', type: 'string', example: 'en'),
        new OAT\Property(
            property: 'segments',
            type: 'array',
            items: new OAT\Items(
                properties: [
                    new OAT\Property(property: 'start', type: 'number', format: 'float', example: 0.0),
                    new OAT\Property(property: 'end', type: 'number', format: 'float', example: 3.5),
                    new OAT\Property(property: 'text', type: 'string', example: 'Hello, world.'),
                ]
            )
        ),
    ],
    type: 'object'
)]
class CaptionResource
{
}
