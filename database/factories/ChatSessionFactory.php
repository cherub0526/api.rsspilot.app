<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Media;
use App\Models\ChatSession;
use Hypervel\Database\Eloquent\Factories\Factory;

class ChatSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'  => User::factory(),
            'media_id' => Media::factory(),
            'title'    => fake()->sentence(4),
        ];
    }
}
