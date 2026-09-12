<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Path;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'second',
    description: 'Whole-second offset into the video (floor of the player currentTime)',
    in: 'path',
    required: true,
    schema: new OAT\Schema(
        type: 'integer',
        example: 125
    )
)]
class Second
{
}
