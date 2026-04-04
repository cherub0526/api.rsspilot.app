<?php

declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OAT;

#[OAT\Response(
    response: 401,
    description: 'Unauthenticated',
    content: new OAT\JsonContent(
        properties: [
            new OAT\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
        ]
    )
)]
class Http401
{
}
