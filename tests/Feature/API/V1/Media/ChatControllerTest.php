<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Media;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\Caption;
use App\Models\Setting;
use App\Models\Summary;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Events\Chat\ChatDoneEvent;
use App\Events\Chat\ChatTokenEvent;
use Hypervel\Support\Facades\Event;
use Tests\Support\FakeChatStreamer;
use App\Utils\AI\ChatStreamerInterface;
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

    private ?FakeChatStreamer $streamer = null;

    /**
     * 綁定假的推論通道並回傳它，供斷言送出的 instructions / messages。
     *
     * 不用 Http::fake()：NeuronAI 走自己建立的 Guzzle client，攔不到。
     */
    private function fakeStreamer(string ...$tokens): FakeChatStreamer
    {
        $this->streamer = new FakeChatStreamer($tokens === [] ? ['Hello'] : $tokens);
        $this->app->instance(ChatStreamerInterface::class, $this->streamer);

        return $this->streamer;
    }

    /** 舊名保留，讓既有測試維持可讀性：語意就是「備妥一個會回應的 AI」。 */
    private function fakeOpenRouter(string $token = 'Hello'): FakeChatStreamer
    {
        return $this->fakeStreamer($token);
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

        // Subscribing a source + syncing its media into userables is what
        // grants access. Direct attach alone (user_sources) is insufficient.
        $user->sources()->attach($source->id, ['notify' => true]);
        $user->media()->syncWithoutDetaching([$media->id]);
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
     * 6-0. 參考資料是摘要的 long_summary.content，不是逐字稿。
     */
    public function testStoreSendsLongSummaryContentToOpenRouter(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        Caption::create([
            'media_id' => $media->id,
            'locale'   => Caption::LOCAL_ZH_TW,
            'primary'  => true,
            'text'     => '這是逐字稿不該被送出',
            'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => '這是逐字稿不該被送出']],
        ]);
        Summary::create([
            'media_id' => $media->id,
            'locale'   => Summary::LOCALE_ZH_TW,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => [
                'short_summary' => '短摘要不該被送出',
                'long_summary'  => [
                    'content'    => "# 影片重點\n\n這是長摘要內文。",
                    'key_points' => ['重點一'],
                ],
            ],
        ]);

        $this->createUserSetting($user);
        $streamer = $this->fakeOpenRouter();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '這部影片在講什麼？']],
        ])->assertStatus(200);

        // 參考資料放在系統提示詞裡（推論層要求 user / assistant 嚴格交替，
        // 不能為了參考資料多插一則 user 訊息）
        $this->assertStringContainsString(
            "# 影片重點\n\n這是長摘要內文。",
            (string) $streamer->instructions,
            'long_summary.content 必須原樣送進系統提示詞'
        );

        $this->assertStringNotContainsString('逐字稿', (string) $streamer->instructions, '逐字稿不該再被送出');
        $this->assertStringNotContainsString('短摘要', (string) $streamer->instructions, '只取 long_summary.content');

        $this->assertSame(
            ['這部影片在講什麼？'],
            $streamer->contents(),
            '訊息陣列只該有本次提問'
        );
    }

    /**
     * 6-0-0. 參考資料與 /summaries 端點取同一份：使用者自己的摘要優先於共用的。
     */
    public function testStorePrefersTheUsersOwnSummaryAsReferenceMaterial(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        Summary::create([
            'media_id' => $media->id,
            'locale'   => Summary::LOCALE_ZH_TW,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['long_summary' => ['content' => '共用的摘要']],
        ]);
        Summary::create([
            'media_id' => $media->id,
            'user_id'  => $user->id,
            'locale'   => Summary::LOCALE_ZH_TW,
            'status'   => Summary::STATUS_COMPLETED,
            'text'     => ['long_summary' => ['content' => '自己的摘要']],
        ]);

        Setting::create([
            'user_id' => $user->id,
            'data'    => ['locale' => Summary::LOCALE_ZH_TW, 'ai' => ['language' => 'en']],
        ]);
        $streamer = $this->fakeOpenRouter();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '重點是什麼？']],
        ])->assertStatus(200);

        $this->assertStringContainsString('自己的摘要', (string) $streamer->instructions);
        $this->assertStringNotContainsString('共用的摘要', (string) $streamer->instructions);
    }

    /**
     * 6-0-1. 只認 status=completed 的摘要，且取最新的那一份。
     *
     * 重跑摘要會先建一筆 status=created、text 還是空的資料列；若只依時間排序，
     * 那筆會蓋掉先前可用的摘要，讓參考資料變成空的。
     */
    public function testStoreIgnoresUncompletedSummaryAndUsesLatestCompletedOne(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        // created_at 不在 Summary::$fillable，mass assignment 會被忽略，
        // 必須事後直接指派才能造出可辨識的時間順序。
        $makeSummary = function (string $status, ?array $text, string $createdAt) use ($media): void {
            $summary = Summary::create([
                'media_id' => $media->id,
                'locale'   => Summary::LOCALE_ZH_TW,
                'status'   => $status,
                'text'     => $text,
            ]);
            $summary->created_at = $createdAt;
            $summary->save();
        };

        $makeSummary(Summary::STATUS_COMPLETED, ['long_summary' => ['content' => '舊的已完成摘要']], '2026-01-01 00:00:00');
        $makeSummary(Summary::STATUS_COMPLETED, ['long_summary' => ['content' => '新的已完成摘要']], '2026-02-01 00:00:00');
        $makeSummary(Summary::STATUS_CREATED, null, '2026-03-01 00:00:00');

        $this->createUserSetting($user);
        $streamer = $this->fakeOpenRouter();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '重點是什麼？']],
        ])->assertStatus(200);

        $this->assertStringContainsString('新的已完成摘要', (string) $streamer->instructions, '應取最新的已完成摘要');
        $this->assertStringNotContainsString('舊的已完成摘要', (string) $streamer->instructions, '不該取到較舊的那份');
    }

    /**
     * 6-0-2. 沒有可用摘要時參考資料為空 —— 不退回逐字稿，且仍要正常回 200。
     */
    public function testStoreSendsNoReferenceWhenMediaHasNoCompletedSummary(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        Caption::create([
            'media_id' => $media->id,
            'locale'   => Caption::LOCAL_ZH_TW,
            'primary'  => true,
            'text'     => '這是逐字稿不該被送出',
            'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => '這是逐字稿不該被送出']],
        ]);

        $this->createUserSetting($user);
        $streamer = $this->fakeOpenRouter();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '這部影片在講什麼？']],
        ])
            ->assertStatus(200)
            ->assertJson(['status' => 'done']);

        $this->assertStringNotContainsString(
            '逐字稿',
            (string) $streamer->instructions,
            '沒有摘要時不該退回逐字稿'
        );
        $this->assertStringNotContainsString(
            'REFERENCE MATERIAL',
            (string) $streamer->instructions,
            '沒有摘要時不該出現空的參考資料區塊'
        );

        $this->assertSame(['這部影片在講什麼？'], $streamer->contents());
    }

    /**
     * 6-1. 使用者從未更新過設定（無 settings 資料列）→ 仍為 200。
     *      讀取 ai.language 時若不容忍缺漏，warning 會被 Hyperf 轉成
     *      ErrorException，整個請求變成 500。
     */
    public function testStoreSucceedsWhenUserHasNoSetting(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->assertNull($user->setting()->first());
        $this->fakeOpenRouter();

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => 'What is this video about?']],
        ])
            ->assertStatus(200)
            ->assertJson(['status' => 'done']);
    }

    /**
     * 6-2. settings 資料列存在但 data 內沒有 ai.language → 仍為 200。
     *      SettingsController::update 會以 `['data' => []]` firstOrCreate，
     *      所以這個狀態是真的會出現的。
     */
    public function testStoreSucceedsWhenSettingHasNoAiLanguage(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        Setting::create(['user_id' => $user->id, 'data' => []]);
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

        $this->fakeStreamer('Hi');

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
     * 8-1. 空的 token 不該廣播出去。
     *
     * NeuronAI 在串流尾端會送出內容為空的 chunk，照送會讓 SSE 前端做無意義的重繪。
     * 過濾後串接結果必須不變。
     */
    public function testStoreSkipsEmptyTokens(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->createUserSetting($user);

        Event::fake([ChatTokenEvent::class, ChatDoneEvent::class]);

        $this->fakeStreamer('這部', '', '影片', '', '');

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [['role' => 'user', 'content' => '這部影片在講什麼？']],
        ])->assertStatus(200);

        Event::assertDispatchedTimes(ChatTokenEvent::class, 2);
        Event::assertNotDispatched(
            ChatTokenEvent::class,
            fn (ChatTokenEvent $event): bool => $event->token === ''
        );

        // 過濾不能影響存下來的完整內容
        $this->assertDatabaseHas('chat_messages', [
            'role'    => ChatMessage::ROLE_AI,
            'content' => '這部影片',
        ]);
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
     * 9-1. 送往 OpenRouter 的 payload 必須帶上先前的對話輪次，
     *      且最後一則 user 訊息只能出現一次（它由 completeStream 帶入結尾）。
     */
    public function testStoreSendsConversationHistoryToOpenRouter(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->createUserSetting($user);
        $streamer = $this->fakeOpenRouter('回答在此');

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'user', 'content' => '第一句話'],
                ['role' => 'assistant', 'content' => '第一回應'],
                ['role' => 'user', 'content' => '第二句話'],
            ],
        ])->assertStatus(200);

        $sent = collect($streamer->messages);

        $this->assertSame(
            [['user', '第一句話'], ['assistant', '第一回應']],
            $sent->filter(fn ($m) => in_array($m['content'], ['第一句話', '第一回應'], true))
                ->map(fn ($m) => [$m['role'], $m['content']])
                ->values()
                ->all(),
            '先前的對話輪次必須原封不動送進 prompt'
        );

        $this->assertSame(
            1,
            $sent->where('content', '第二句話')->count(),
            '最後一則 user 訊息不可重複出現'
        );

        $this->assertSame(
            ['user', '第二句話'],
            [$sent->last()['role'], $sent->last()['content']],
            '最後一則 user 訊息必須落在訊息陣列結尾'
        );
    }

    /**
     * 9-2. 送出的訊息必須以 user 開頭且 user / assistant 嚴格交替。
     *
     * ChatValidator 不限制客戶端送來的順序，但推論層會因為序列不合法而整個失敗，
     * 所以連續同角色要合併、開頭的 assistant 要丟掉。
     */
    public function testStoreNormalisesMessagesIntoStrictAlternation(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->createUserSetting($user);
        $streamer = $this->fakeStreamer('好的');

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
            'messages' => [
                ['role' => 'assistant', 'content' => '開場白'],   // 開頭的 assistant → 丟掉
                ['role' => 'user', 'content' => '第一句'],
                ['role' => 'system', 'content' => '補充設定'],     // system 併入 user
                ['role' => 'assistant', 'content' => '回應一'],
                ['role' => 'assistant', 'content' => '回應二'],   // 連續 assistant → 合併
                ['role' => 'user', 'content' => '最後提問'],
            ],
        ])->assertStatus(200);

        $this->assertSame(
            [
                ['role' => 'user', 'content' => "第一句\n\n補充設定"],
                ['role' => 'assistant', 'content' => "回應一\n\n回應二"],
                ['role' => 'user', 'content' => '最後提問'],
            ],
            $streamer->messages
        );

        // 正規化後仍必須是 user 開頭、嚴格交替
        $roles = array_column($streamer->messages, 'role');
        $this->assertSame('user', $roles[0]);
        foreach ($roles as $i => $role) {
            $this->assertSame($i % 2 === 0 ? 'user' : 'assistant', $role);
        }
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

    /**
     * 傳入屬於自己但綁定不同 media 的 session_id → 404.
     */
    public function testStoreReturns404ForSessionWithWrongMedia(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media1 = Media::factory()->create(['source_id' => $source->id]);
        $media2 = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media2->id,
        ]);

        $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media1->id]), [
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
        $media = Media::factory()->create(['source_id' => $source->id]);

        $this->json('GET', route('api.v1.media.chat.sessions.index', ['mediaId' => $media->id]))
            ->assertStatus(404);
    }

    /**
     * 正常情境：只回傳此 user 在此 media 的 sessions。
     */
    public function testSessionsIndexReturnsUserSessions(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $other = User::factory()->create();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $media2 = Media::factory()->create(['source_id' => $source->id]);

        ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id, 'title' => 'My session']);
        ChatSession::create(['user_id' => $other->id, 'media_id' => $media->id, 'title' => 'Other user session']);
        ChatSession::create(['user_id' => $user->id, 'media_id' => $media2->id, 'title' => 'Other media session']);

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
        $media = Media::factory()->create();
        $user = User::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);

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
        $other = User::factory()->create();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create(['user_id' => $other->id, 'media_id' => $media->id]);

        $this->json('GET', route('api.v1.media.chat.sessions.show', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))->assertStatus(404);
    }

    /**
     * media 不可存取（非 free、未訂閱）→ 404.
     */
    public function testSessionShowReturns404WhenMediaNotAccessible(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);

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
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
            'title'    => 'Test session',
        ]);

        ChatMessage::create([
            'session_id' => $session->id,
            'role'       => 'user',
            'content'    => 'Hello',
            'created_at' => now(),
        ]);
        ChatMessage::create([
            'session_id' => $session->id,
            'role'       => 'ai',
            'content'    => 'World',
            'created_at' => now()->addSecond(),
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

    // ================================================================
    // DELETE /v1/media/{mediaId}/chat/sessions/{sessionId}  (session destroy)
    // ================================================================

    /**
     * 未登入 → 401.
     */
    public function testSessionDestroyRequiresAuth(): void
    {
        $media = Media::factory()->create();
        $user = User::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);

        $this->json('DELETE', route('api.v1.media.chat.sessions.destroy', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))->assertStatus(401);
    }

    /**
     * session 不屬於此 user → 404.
     */
    public function testSessionDestroyReturns404WhenNotOwned(): void
    {
        $this->fakeLogin();
        $other = User::factory()->create();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create(['user_id' => $other->id, 'media_id' => $media->id]);

        $this->json('DELETE', route('api.v1.media.chat.sessions.destroy', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))->assertStatus(404);
    }

    /**
     * media 不可存取（非 free、未訂閱）→ 404.
     */
    public function testSessionDestroyReturns404WhenMediaNotAccessible(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => false]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);

        $this->json('DELETE', route('api.v1.media.chat.sessions.destroy', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))->assertStatus(404);
    }

    /**
     * 正常情境：刪除 session（soft delete），回應 200。
     */
    public function testSessionDestroySucceeds(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $session = ChatSession::create([
            'user_id'  => $user->id,
            'media_id' => $media->id,
            'title'    => 'Test session',
        ]);

        $this->json('DELETE', route('api.v1.media.chat.sessions.destroy', [
            'mediaId'   => $media->id,
            'sessionId' => $session->id,
        ]))->assertStatus(200);

        $this->assertSoftDeleted('chat_sessions', ['id' => $session->id]);
    }

    /**
     * 刪除他人 session 不應受影響。
     */
    public function testSessionDestroyDoesNotAffectOtherUsersSessions(): void
    {
        /** @var User $user */
        $user = $this->fakeLogin();
        $other = User::factory()->create();
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);

        $mySession = ChatSession::create(['user_id' => $user->id, 'media_id' => $media->id]);
        $otherSession = ChatSession::create(['user_id' => $other->id, 'media_id' => $media->id]);

        $this->json('DELETE', route('api.v1.media.chat.sessions.destroy', [
            'mediaId'   => $media->id,
            'sessionId' => $mySession->id,
        ]))->assertStatus(200);

        $this->assertSoftDeleted('chat_sessions', ['id' => $mySession->id]);
        $this->assertDatabaseHas('chat_sessions', ['id' => $otherSession->id, 'deleted_at' => null]);
    }
}
