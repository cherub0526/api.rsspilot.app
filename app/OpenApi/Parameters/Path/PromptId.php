<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Path;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'promptId',
    description: 'Custom prompt ID',
    in: 'path',
    required: true,
    schema: new OAT\Schema(
        type: 'string',
        example: '01k9v7m2q8n4r6t0w3y5z7b1c9'
    )
)]
class PromptId
{
}
