<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Feedback;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class FeedbackUserTest extends TestCase
{
    use RefreshDatabase;

    public function testFeedbackCanHaveUser(): void
    {
        $user = User::factory()->create();

        $feedback = Feedback::create([
            'user_id' => $user->id,
            'content' => 'Great app!',
            'status'  => Feedback::STATUS_CREATED,
        ]);

        $this->assertDatabaseHas('feedbacks', [
            'id'      => $feedback->id,
            'user_id' => $user->id,
        ]);

        $this->assertEquals($user->id, $feedback->user->id);
    }

    public function testFeedbackUserIdIsNullable(): void
    {
        $feedback = Feedback::create([
            'content' => 'Anonymous feedback',
            'status'  => Feedback::STATUS_CREATED,
        ]);

        $this->assertNull($feedback->user_id);
    }
}
