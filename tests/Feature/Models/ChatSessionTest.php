<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use Hypervel\Foundation\Testing\RefreshDatabase;

class ChatSessionTest extends TestCase
{
    use RefreshDatabase;

    public function testCanCreateChatSession(): void
    {
        $user  = User::factory()->create();
        $media = Media::factory()->create();

        $session = ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
            'title'    => 'Test conversation',
        ]);

        $this->assertDatabaseHas('chat_sessions', [
            'user_id'  => $user->id,
            'media_id' => $media->id,
        ]);

        $this->assertNotNull($session->id);
    }

    public function testChatMessageBelongsToSession(): void
    {
        $user    = User::factory()->create();
        $media   = Media::factory()->create();
        $session = ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
        ]);

        ChatMessage::create([
            'session_id' => $session->id,
            'role'       => ChatMessage::ROLE_USER,
            'content'    => 'What is this video about?',
        ]);

        ChatMessage::create([
            'session_id' => $session->id,
            'role'       => ChatMessage::ROLE_AI,
            'content'    => 'This video is about...',
        ]);

        $this->assertCount(2, $session->messages);
    }

    public function testUserChatSessionsRelationship(): void
    {
        $user  = User::factory()->create();
        $media = Media::factory()->create();

        ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);

        $this->assertCount(1, $user->chatSessions);
    }
}
