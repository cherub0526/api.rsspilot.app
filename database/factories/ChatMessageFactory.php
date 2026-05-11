<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use Hypervel\Database\Eloquent\Factories\Factory;

class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'session_id' => ChatSession::factory(),
            'role'       => fake()->randomElement([ChatMessage::ROLE_USER, ChatMessage::ROLE_AI]),
            'content'    => fake()->paragraph(),
            'created_at' => now(),
        ];
    }
}
