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
        type: 'integer',
        example: 12
    )
)]
class PromptId
{
}
