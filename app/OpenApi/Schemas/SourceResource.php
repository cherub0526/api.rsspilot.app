<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'SourceResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'name', type: 'string', example: 'Google Developers'),
        new OAT\Property(property: 'url', type: 'string', example: 'https://www.youtube.com/channel/UCAuUUnT6oDeKwE6v1NGQxug'),
        new OAT\Property(property: 'type', type: 'string', enum: ['channel', 'playlist'], example: 'channel'),
        new OAT\Property(property: 'notify', type: 'boolean', example: true),
        new OAT\Property(property: 'thumbnail', type: 'string', nullable: true, example: 'https://yt3.googleusercontent.com/ytc/example'),
    ],
    type: 'object'
)]
class SourceResource
{
}
