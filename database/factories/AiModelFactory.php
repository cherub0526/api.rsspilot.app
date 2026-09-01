<?php

declare(strict_types=1);

namespace Database\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;

class AiModelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'           => fake()->words(2, true),
            'provider_model' => 'openai/' . fake()->slug(2),
            'enabled'        => true,
            'sort'           => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
