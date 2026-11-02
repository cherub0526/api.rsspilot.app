<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'SourceSkillResource',
    properties: [
        new OAT\Property(property: 'name', type: 'string', example: 'rsspilot-example-channel-abcdef'),
        new OAT\Property(property: 'description', type: 'string', example: 'Methods and checklists distilled by RSSPilot …'),
        new OAT\Property(
            property: 'outline',
            description: 'Plain-text `##` headings of the skill, at most 15 items of 80 characters each.',
            type: 'array',
            items: new OAT\Items(type: 'string'),
            example: ['Core principles', 'Checklist']
        ),
        new OAT\Property(
            property: 'content',
            description: 'Full SKILL.md, starting with `---` frontmatter. Always null when the user\'s plan does not include skills.',
            type: 'string',
            nullable: true
        ),
        new OAT\Property(property: 'video_count', type: 'integer', example: 30),
        new OAT\Property(property: 'generated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
class SourceSkillResource
{
}
