<?php

declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OAT;

#[OAT\Response(
    response: 400,
    description: 'Invalid request parameters',
    content: new OAT\JsonContent(
        properties: [
            new OAT\Property(
                property: 'errors',
                type: 'object',
                example: ['field' => ['The field is required.']]
            ),
        ]
    )
)]
class Http400
{
}
