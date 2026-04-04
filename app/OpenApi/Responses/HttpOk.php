<?php

declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OAT;

#[OAT\Response(
    response: 200,
    description: 'Successful operation',
    content: new OAT\JsonContent(
        properties: [
            new OAT\Property(property: 'message', type: 'string', example: 'OK.'),
        ]
    )
)]
class HttpOk
{
}
