<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Path;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'summaryId',
    description: 'Summary ID (ULID)',
    in: 'path',
    required: true,
    schema: new OAT\Schema(
        type: 'string',
        example: '01JCXYZ123456789ABCDEFGHIJ'
    )
)]
class SummaryId
{
}
