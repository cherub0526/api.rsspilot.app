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
            property: 'checksum',
            description: 'Lowercase hex SHA-256 of the JPEG bytes — this is what identifies the frame.',
            type: 'string',
            example: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
        ),
        new OAT\Property(
            property: 'url',
            type: 'string',
            description: 'Pre-signed S3 URL, valid for 24 hours. Re-request instead of storing it.',
            example: 'https://bucket.s3.amazonaws.com/media/01JCXYZ.../thumbnails/000125.e3b0c442....jpg?X-Amz-Signature=...'
        ),
    ],
    type: 'object'
)]
class ThumbnailResource
{
}
