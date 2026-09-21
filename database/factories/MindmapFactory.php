<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mindmap;
use Hypervel\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mindmap>
 */
class MindmapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'language' => 'en',
            'status'   => Mindmap::STATUS_COMPLETED,
        ];
    }
}
