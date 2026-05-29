<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Users;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Media;
use App\Models\Source;
use App\Models\User;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SessionsControllerTest extends TestCase
{
    use RefreshDatabase;

    // ================================================================
    // GET /v1/users/sessions
    // ================================================================

    /**
     * 未登入 → 401.
     */
    public function testIndexRequiresAuth(): void
    {
        $this->json('GET', route('api.v1.users.sessions.index'))
            ->assertStatus(401);
    }

    /**
     * 無 session 時回傳空分頁。
     */
    public function testIndexReturnsEmptyWhenNoSessions(): void
    {
        $this->fakeLogin();

        $this->json('GET', route('api.v1.users.sessions.index'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    /**
     * 只回傳當前 user 的 sessions，不包含其他 user 的。
     */
    public function testIndexOnlyReturnsCurrentUserSessions(): void
    {
        /** @var User $user */
        $user  = $this->fakeLogin();
        $other = User::factory()->create();
        $media = Media::factory()->create();

        ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id, 'title' => 'Mine']);
        ChatSession::create(['user_id' => $other->id, 'media_id' => $media->id, 'title' => 'Not mine']);

        $this->json('GET', route('api.v1.users.sessions.index'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    /**
     * Sessions 依 created_at desc 排序。
     */
    public function testIndexReturnsByCreatedAtDesc(): void
    {
        /** @var User $user */
        $user  = $this->fakeLogin();
        $media = Media::factory()->create();

        $older = ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id, 'title' => 'Older']);
        $older->created_at = now()->subHour();
        $older->save();

        ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id, 'title' => 'Newer']);

        $this->json('GET', route('api.v1.users.sessions.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Newer')
            ->assertJsonPath('data.1.title', 'Older');
    }

    /**
     * 回傳值包含正確欄位結構（含 media, message_count, last_messages）。
     */
    public function testIndexReturnsExpectedStructure(): void
    {
        /** @var User $user */
        $user    = $this->fakeLogin();
        $source  = Source::factory()->create(['free' => true]);
        $media   = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
            'title'    => 'Test',
        ]);

        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'Hello', 'created_at' => now()]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'ai',   'content' => 'World', 'created_at' => now()->addSecond()]);

        $this->json('GET', route('api.v1.users.sessions.index'))
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'title', 'created_at', 'updated_at',
                    'message_count', 'media', 'last_messages',
                ]],
            ])
            ->assertJsonPath('data.0.message_count', 2)
            ->assertJsonCount(2, 'data.0.last_messages')
            ->assertJsonPath('data.0.last_messages.0.role', 'user')
            ->assertJsonPath('data.0.last_messages.1.role', 'ai');
    }

    /**
     * message_count 正確計算，last_messages 只回傳最後兩則。
     */
    public function testIndexReturnsLastTwoMessagesOnly(): void
    {
        /** @var User $user */
        $user    = $this->fakeLogin();
        $media   = Media::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);

        foreach (range(1, 5) as $i) {
            ChatMessage::create([
                'session_id' => $session->id,
                'role'       => 'user',
                'content'    => "Message {$i}",
                'created_at' => now()->addSeconds($i),
            ]);
        }

        $this->json('GET', route('api.v1.users.sessions.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.message_count', 5)
            ->assertJsonCount(2, 'data.0.last_messages')
            ->assertJsonPath('data.0.last_messages.0.content', 'Message 4')
            ->assertJsonPath('data.0.last_messages.1.content', 'Message 5');
    }

    /**
     * Media 資訊包含在回傳中。
     */
    public function testIndexIncludesMediaInfo(): void
    {
        /** @var User $user */
        $user  = $this->fakeLogin();
        $media = Media::factory()->create(['title' => 'My Video']);
        ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);

        $this->json('GET', route('api.v1.users.sessions.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.media.title', 'My Video');
    }
}
