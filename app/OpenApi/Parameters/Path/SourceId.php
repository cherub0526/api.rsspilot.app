<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Path;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'sourceId',
    description: 'Source ID',
    in: 'path',
    required: true,
    schema: new OAT\Schema(type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ')
)]
class SourceId
{
}
