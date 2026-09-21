<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Path;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'checksum',
    description: 'Lowercase hex SHA-256 of the JPEG bytes',
    in: 'path',
    required: true,
    schema: new OAT\Schema(
        type: 'string',
        example: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
    )
)]
class Checksum
{
}
