<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'RSSResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'type', type: 'string', example: 'youtube'),
        new OAT\Property(property: 'list_id', type: 'string', nullable: true, example: 'PLu96Vzt7fGU7IC5vsXXKgUWwASv5FTWMx'),
        new OAT\Property(property: 'url', type: 'string', example: 'https://www.youtube.com/channel/UCxxxxxx'),
        new OAT\Property(property: 'title', type: 'string', example: 'Channel Title'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-01-01 12:00:00'),
    ],
    type: 'object'
)]
class RSSResource
{
}
