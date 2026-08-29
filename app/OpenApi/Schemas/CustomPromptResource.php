<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'CustomPromptResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '12'),
        new OAT\Property(property: 'title', type: 'string', example: '學習筆記摘要'),
        new OAT\Property(property: 'content', type: 'string', example: '請以學習筆記的風格整理這部影片的重點…'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object'
)]
class CustomPromptResource
{
}
