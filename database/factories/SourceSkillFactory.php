<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use App\Models\SourceSkill;
use Hypervel\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceSkill>
 */
class SourceSkillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id'    => Source::factory(),
            'language'     => 'en',
            'name'         => 'rsspilot-' . $this->faker->unique()->slug(2),
            'description'  => $this->faker->sentence(),
            'content'      => "---\nname: example\ndescription: example\n---\n\n## Principles\n",
            'references'   => "# Videos\n",
            'video_count'  => 3,
            'media_ids'    => [],
            'status'       => SourceSkill::STATUS_COMPLETED,
            'generated_at' => now(),
        ];
    }
}
