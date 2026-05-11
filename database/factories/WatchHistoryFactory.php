<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Media;
use App\Models\WatchHistory;
use Hypervel\Database\Eloquent\Factories\Factory;

class WatchHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'          => User::factory(),
            'media_id'         => Media::factory(),
            'progress_seconds' => fake()->numberBetween(0, 3600),
            'completed'        => fake()->boolean(),
            'watched_at'       => fake()->dateTimeThisMonth(),
        ];
    }
}
