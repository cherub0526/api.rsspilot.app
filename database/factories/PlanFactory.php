<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Hypervel\Support\Str;
use Hypervel\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id'            => strtolower(Str::ulid()->toString()),
            'title'         => fake()->name(),
            'description'   => fake()->sentence(),
            'channel_limit' => fake()->numberBetween(1, 10),
            'video_limit'   => fake()->numberBetween(3, 100),
            // 0 = 不限制。測試要驗額度時自行覆寫，預設不讓額度干擾無關的測試。
            'chat_limit' => 0,
            'sort'       => fake()->numberBetween(0, 5),
            'status'     => fake()->randomElement(array_keys(Plan::$statusMaps)),
        ];
    }
}
