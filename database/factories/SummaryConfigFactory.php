<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\SummaryConfig;
use Hypervel\Database\Eloquent\Factories\Factory;

class SummaryConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'title'       => fake()->sentence(3),
            'prompt_type' => fake()->randomElement(array_keys(SummaryConfig::$promptTypeMaps)),
            'content'     => fake()->paragraph(),
            'ai_model'    => null,
        ];
    }
}
