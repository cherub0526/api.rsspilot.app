<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\Setting;
use App\Models\ChatSession;
use App\Events\Chat\ChatDoneEvent;
use Hypervel\Support\Facades\Http;
use App\Events\Chat\ChatTokenEvent;
use Hypervel\Support\Facades\Event;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────────
    // Shared helpers
    // ────────────────────────────────────────────────────────────────

    /** Stub OpenRouter to emit one token "Hello" then [DONE]. */
    private function fakeOpenRouter(string $token = 'Hello'): void
    {
        $escapedToken = json_encode($token, JSON_UNESCAPED_UNICODE);

        Http::fake([
            'openrouter.ai/*' => Http::response(
                "data: {\"choices\":[{\"delta\":{\"content\":{$escapedToken}}}]}\n\ndata: [DONE]\n\n",
                200
            ),
        ]);
    }

    /** Persist user AI-language setting (required by AssistantTemplate). */
    private function createUserSetting(User $user, string $language = 'en'): void
    {
        Setting::create([
            'user_id' => $user->id,
            'data'    => ['ai' => ['language' => $language]],
        ]);
    }

    // ================================================================
    // POST /v1/media/{mediaId}/chat  (store)
    // ================================================================

    /**
     * 1. Unauthenticated request → 401.
     */
    public function testStoreRequiresAuth(): void
    {
        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => 'test']],
        ])->assertStatus(401);
    }

    /**
     * 2. messages 欄位驗證 → 422.
     *
     * 使用 free source，排除存取控制帶來的 404。
     */
    public function testStoreValidatesMessages(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $uri = route('api.v1.media.chat.store', ['mediaId' => $media->id]);

        // 完全缺少 messages 欄位
        $this->json('POST', $uri, [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['messages']]);

        // 空陣列（min:1）
        $this->json('POST', $uri, ['messages' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['messages']]);

        // 缺少 role
        $this->json('POST', $uri, ['messages' => [['content' => 'hi']]])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['messages.0.role']]);

        // 非法 role 值（不在 user/assistant/system 之中）
        $this->json('POST', $uri, ['messages' => [['role' => 'admin', 'content' => 'hi']]])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['messages.0.role']]);

        // 缺少 content
        $this->json('POST', $uri, ['messages' => [['role' => 'user']]])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['messages.0.content']]);
    }

    /**
     * 傳入非 ULID 格式的 session_id → 422.
     */
    public function testStoreValidatesSessionIdFormat(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $uri = route('api.v1.media.chat.store', ['mediaId' => $media->id]);

        $this->json('POST', $uri, [
            'session_id' => 'not-a-ulid',
            'messages'   => [['role' => 'user', 'content' => 'hi']],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['session_id']]);
    }

    /**
     * 3. 合法格式但不存在的 mediaId → 404.
     */
    public function testStoreReturns404ForNonExistentMedia(): void
    {
        $this->fakeLogin();

        // 01000000000000000000000000 符合路由 regex [0-7][0-9a-hjkmnp-tv-z]{25}
        // 但資料庫中不存在此 ID
        $this->json('POST', '/api/v1/media/01000000000000000000000000/chat', [
            'messages' => [['role' => 'user', 'content' => 'test']],
        ])->assertStatus(404);
    }

    /**
     * 4. Media 存在但使用者無存取權限 → 404.
     *
     * 條件：非 free source、使用者未訂閱、未透過 userables 直接擁有。
     */
    public function testStoreReturns404WhenUserHasNoAccess(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => 'test']],
        ])->assertStatus(404);
    }

    /**
     * 5. 使用者已訂閱來源 → 200 {"status":"done"}.
     */
    public function testStoreSucceedsWhenUserSubscribedToSource(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $user->sources()->attach($source->id, ['notify' => true]);
        $this->createUserSetting($user);
        $this->fakeOpenRouter();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => 'What is this video about?']],
        ])
            ->assertStatus(200)
            ->assertJson(['status' => 'done']);
    }

    /**
     * 6. free source 下的 media，任何已驗證使用者皆可存取 → 200.
     */
    public function testStoreSucceedsForFreeSourceMedia(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->createUserSetting($user);
        $this->fakeOpenRouter();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => 'What is this video about?']],
        ])
            ->assertStatus(200)
            ->assertJson(['status' => 'done']);
    }

    /**
     * 7. 使用者透過 userables 直接擁有 media（無 source）→ 200.
     */
    public function testStoreSucceedsForDirectlyOwnedMedia(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $media = Media::factory()->create(); // 無 source_id

        $user->media()->attach($media->id);
        $this->createUserSetting($user);
        $this->fakeOpenRouter();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => 'Summarise in one sentence.']],
        ])
            ->assertStatus(200)
            ->assertJson(['status' => 'done']);
    }

    /**
     * 8. 成功完成時 dispatch ChatTokenEvent（含正確 token/userId/mediaId）
     *    與 ChatDoneEvent（含正確 userId/mediaId）。
     */
    public function testStoreDispatchesChatTokenAndDoneEvents(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->createUserSetting($user);

        Event::fake([ChatTokenEvent::class, ChatDoneEvent::class]);

        Http::fake([
            'openrouter.ai/*' => Http::response(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\ndata: [DONE]\n\n",
                200
            ),
        ]);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => 'Hello?']],
        ])->assertStatus(200);

        Event::assertDispatched(
            ChatTokenEvent::class,
            function (ChatTokenEvent $event) use ($user, $media): bool {
                return $event->userId === (string) $user->getKey()
                    && $event->mediaId === $media->id
                    && $event->token === 'Hi';
            }
        );

        Event::assertDispatched(
            ChatDoneEvent::class,
            function (ChatDoneEvent $event) use ($user, $media): bool {
                return $event->userId === (string) $user->getKey()
                    && $event->mediaId === $media->id;
            }
        );
    }

    /**
     * 9. 多輪對話歷史 → 只有最後一則 user 訊息觸發 AI，整體仍回傳 200.
     */
    public function testStoreAcceptsConversationHistory(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->createUserSetting($user);
        $this->fakeOpenRouter('回答在此');

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'user', 'content' => '第一句話'],
                ['role' => 'assistant', 'content' => '第一回應'],
                ['role' => 'user', 'content' => '第二句話'],
            ],
        ])
            ->assertStatus(200)
            ->assertJson(['status' => 'done']);
    }

    /**
     * 不傳 session_id → 自動建立 ChatSession，保存 user 與 AI 訊息。
     */
    public function testStoreCreatesSessionAndSavesMessages(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->createUserSetting($user);
        $this->fakeOpenRouter('Hello');

        $response = $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => 'What is this about?']],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'session_id']);

        $sessionId = $response->json('session_id');

        $this->assertDatabaseHas('chat_sessions', [
            'id'       => $sessionId,
            'user_id'  => $user->id,
            'media_id' => $media->id,
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'session_id' => $sessionId,
            'role'       => 'user',
            'content'    => 'What is this about?',
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'session_id' => $sessionId,
            'role'       => 'ai',
            'content'    => 'Hello',
        ]);
    }

    /**
     * 傳入既有合法 session_id → 繼續該 session，不建立新 session。
     */
    public function testStoreContinuesExistingSession(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
            'title'    => 'First question',
        ]);

        $this->createUserSetting($user);
        $this->fakeOpenRouter('World');

        $response = $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'session_id' => $session->id,
            'messages'   => [['role' => 'user', 'content' => 'Follow-up question']],
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'done', 'session_id' => $session->id]);

        // 不建立新 session
        $this->assertDatabaseCount('chat_sessions', 1);

        // 訊息掛在既有 session 下
        $this->assertDatabaseHas('chat_messages', [
            'session_id' => $session->id,
            'role'       => 'user',
            'content'    => 'Follow-up question',
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'session_id' => $session->id,
            'role'       => 'ai',
            'content'    => 'World',
        ]);
    }

    /**
     * 傳入不屬於此 user 的 session_id → 404.
     */
    public function testStoreReturns404ForSessionNotOwnedByUser(): void
    {
        $this->fakeLogin();
        $other = User::factory()->create();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create([
            'user_id'  => $other->id,
            'media_id' => $media->id,
        ]);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'session_id' => $session->id,
            'messages'   => [['role' => 'user', 'content' => 'hi']],
        ])->assertStatus(404);
    }

    // ================================================================
    // GET /v1/media/{mediaId}/chat/stream  (stream)
    //
    // 注意：200 SSE 長連線的完整流程（含 token 接收）屬於整合測試範疇。
    // 在 in-process 測試中，stream() 的 Swoole Channel::pop(30.0)
    // 會無限期等待事件導致逾時，因此只測試 middleware 層（401/404）——
    // 這些路徑在 resolveMedia() 或 auth middleware 中提早中斷，
    // 不會進入 streaming 迴圈。
    // ================================================================

    /**
     * 10. Unauthenticated request → 401.
     */
    public function testStreamRequiresAuth(): void
    {
        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.chat.stream', ['mediaId' => $media->id]))
            ->assertStatus(401);
    }

    /**
     * 11. 合法格式但不存在的 mediaId → 404.
     */
    public function testStreamReturns404ForNonExistentMedia(): void
    {
        $this->fakeLogin();

        $this->json('GET', '/api/v1/media/01000000000000000000000000/chat/stream')
            ->assertStatus(404);
    }

    /**
     * 12. Media 存在但使用者無存取權限（非 free、未訂閱、未擁有）→ 404.
     */
    public function testStreamReturns404WhenUserHasNoAccess(): void
    {
        $this->fakeLogin();

        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.chat.stream', ['mediaId' => $media->id]))
            ->assertStatus(404);
    }

    // ================================================================
    // GET /v1/media/{mediaId}/chat/sessions  (sessions index)
    // ================================================================

    /**
     * 未登入 → 401.
     */
    public function testSessionsIndexRequiresAuth(): void
    {
        $media = Media::factory()->create();

        $this->json('GET', route('api.v1.media.chat.sessions.index', ['mediaId' => $media->id]))
            ->assertStatus(401);
    }

    /**
     * 使用者無 media 存取權限 → 404.
     */
    public function testSessionsIndexReturns404WhenNoAccess(): void
    {
        $this->fakeLogin();
        $source = Source::factory()->create(['free' => false]);
        $media  = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.chat.sessions.index', ['mediaId' => $media->id]))
            ->assertStatus(404);
    }

    /**
     * 正常情境：只回傳此 user 在此 media 的 sessions。
     */
    public function testSessionsIndexReturnsUserSessions(): void
    {
        /** @var User $user */
        $user    = $this->fakeLogin();
        $other   = User::factory()->create();
        $source  = Source::factory()->create(['free' => true]);
        $media   = Media::factory()->create(['source_id' => $source->id]);
        $media2  = Media::factory()->create(['source_id' => $source->id]);

        \App\Models\ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id, 'title' => 'My session']);
        \App\Models\ChatSession::create(['user_id' => $other->id, 'media_id' => $media->id, 'title' => 'Other user session']);
        \App\Models\ChatSession::create(['user_id' => $user->id, 'media_id' => $media2->id, 'title' => 'Other media session']);

        $this->json('GET', route('api.v1.media.chat.sessions.index', ['mediaId' => $media->id]))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'My session');
    }

    // ================================================================
    // GET /v1/media/{mediaId}/chat/sessions/{sessionId}  (session show)
    // ================================================================

    /**
     * 未登入 → 401.
     */
    public function testSessionShowRequiresAuth(): void
    {
        $media   = Media::factory()->create();
        $user    = User::factory()->create();
        $session = \App\Models\ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);

        $this->json('GET', route('api.v1.media.chat.sessions.show', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))->assertStatus(401);
    }

    /**
     * session 不屬於此 user → 404.
     */
    public function testSessionShowReturns404WhenNotOwned(): void
    {
        $this->fakeLogin();
        $other   = User::factory()->create();
        $source  = Source::factory()->create(['free' => true]);
        $media   = Media::factory()->create(['source_id' => $source->id]);
        $session = \App\Models\ChatSession::create(['user_id' => $other->id, 'media_id' => $media->id]);

        $this->json('GET', route('api.v1.media.chat.sessions.show', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))->assertStatus(404);
    }

    /**
     * 正常情境：回傳 session 與訊息列表。
     */
    public function testSessionShowReturnsSessionWithMessages(): void
    {
        /** @var User $user */
        $user    = $this->fakeLogin();
        $source  = Source::factory()->create(['free' => true]);
        $media   = Media::factory()->create(['source_id' => $source->id]);
        $session = \App\Models\ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
            'title'    => 'Test session',
        ]);

        \App\Models\ChatMessage::create([
            'session_id' => $session->id,
            'role'       => 'user',
            'content'    => 'Hello',
            'created_at' => now(),
        ]);
        \App\Models\ChatMessage::create([
            'session_id' => $session->id,
            'role'       => 'ai',
            'content'    => 'World',
            'created_at' => now(),
        ]);

        $this->json('GET', route('api.v1.media.chat.sessions.show', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))
            ->assertStatus(200)
            ->assertJsonPath('id', $session->id)
            ->assertJsonPath('title', 'Test session')
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.1.role', 'ai');
    }
}
