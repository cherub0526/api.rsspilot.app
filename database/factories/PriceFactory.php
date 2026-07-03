<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Price;
use Hypervel\Support\Str;
use Hypervel\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id'      => strtolower(Str::ulid()->toString()),
            'plan_id' => strtolower(Str::ulid()->toString()),
            'unit'    => fake()->randomElement(array_keys(Price::$unitMaps)),
            'price'   => fake()->numberBetween(0, 1000),
        ];
    }
}
