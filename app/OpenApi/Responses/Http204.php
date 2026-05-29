<?php

declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OAT;

#[OAT\Response(
    response: 204,
    description: 'No Content'
)]
class Http204
{
}
