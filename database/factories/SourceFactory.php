<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use Hypervel\Database\Eloquent\Factories\Factory;

class SourceFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement([Source::TYPE_YOUTUBE_CHANNEL, Source::TYPE_YOUTUBE_PLAYLIST]);
        $externalId = $type === Source::TYPE_YOUTUBE_CHANNEL
            ? 'UC' . fake()->regexify('[A-Za-z0-9_-]{22}')
            : 'PL' . fake()->regexify('[A-Za-z0-9_-]{32}');

        return [
            'type'        => $type,
            'external_id' => $externalId,
            'title'       => fake()->sentence(3),
            'url'         => 'https://www.youtube.com/channel/' . $externalId,
            'status'      => Source::STATUS_ACTIVE,
        ];
    }
}
