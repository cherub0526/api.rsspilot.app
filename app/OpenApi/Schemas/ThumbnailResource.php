<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'ThumbnailResource',
    properties: [
        new OAT\Property(property: 'media_id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'second', type: 'integer', example: 125),
        new OAT\Property(
            property: 'url',
            type: 'string',
            description: 'Pre-signed S3 URL, valid for 24 hours. Re-request instead of storing it.',
            example: 'https://bucket.s3.amazonaws.com/media/01JCXYZ.../thumbnails/000125.jpg?X-Amz-Signature=...'
        ),
    ],
    type: 'object'
)]
class ThumbnailResource
{
}
