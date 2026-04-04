<?php

declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OAT;

#[OAT\Response(
    response: 403,
    description: 'Forbidden',
    content: new OAT\JsonContent(
        properties: [
            new OAT\Property(property: 'message', type: 'string', example: 'This action is unauthorized.'),
        ]
    )
)]
class Http403
{
}
