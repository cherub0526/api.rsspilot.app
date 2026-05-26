<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'UserResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'name', type: 'string', example: 'John Doe'),
        new OAT\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
        new OAT\Property(property: 'account', type: 'string', example: 'johndoe'),
        new OAT\Property(
            property: 'avatar',
            type: 'string',
            nullable: true,
            example: 'https://cdn.example.com/avatars/01JCXYZ.../uuid.jpg'
        ),
        new OAT\Property(property: 'setting', type: 'object'),
    ],
    type: 'object'
)]
class UserResource
{
}
