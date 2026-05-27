<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Query;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'keyword',
    description: 'Filter media by title keyword (case-insensitive partial match)',
    in: 'query',
    required: false,
    schema: new OAT\Schema(type: 'string', maxLength: 255, example: 'Laravel tutorial')
)]
class Keyword
{
}
