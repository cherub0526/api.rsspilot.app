# Chat Session 實作計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 讓 chat 互動自動建立/延續 ChatSession，保存 user 與 AI 訊息，並新增兩支查詢端點。

**Architecture:** `store()` 接受 optional `session_id`，不傳則自動建立新 session；token buffer 在串流過程中累積，`[DONE]` 後整體寫入 ChatMessage。查詢端點直接在 ChatController 新增 `sessions()` 與 `sessionShow()`，沿用既有 `resolveMedia()` 存取控制。

**Tech Stack:** Hypervel (Laravel-style), Eloquent, PHPUnit Feature tests, swagger-php Attributes

---

## 檔案清單

### 新增
| 檔案 | 責任 |
|---|---|
| `app/Http/Resources/ChatMessageResource.php` | 單則訊息（id, role, content, created_at） |
| `app/Http/Resources/ChatSessionResource.php` | Session 清單項目（id, title, created_at, updated_at） |
| `app/Http/Resources/ChatSessionDetailResource.php` | Session 詳情（含 messages 陣列） |
| `app/OpenApi/Schemas/ChatMessageSchema.php` | OAT Schema for ChatMessage |
| `app/OpenApi/Schemas/ChatSessionSchema.php` | OAT Schema for ChatSession（清單用） |
| `app/OpenApi/Schemas/ChatSessionDetailSchema.php` | OAT Schema for ChatSessionDetail（含 messages） |

### 修改
| 檔案 | 修改項目 |
|---|---|
| `app/Validators/ChatValidator.php` | `setStoreRules()` 新增 optional `session_id` ULID 驗證 |
| `app/Http/Controllers/API/V1/Media/ChatController.php` | `store()` 加入 session 邏輯＋訊息保存；新增 `sessions()` / `sessionShow()` |
| `routes/v1.php` | 在 chat group 新增兩條 GET 路由 |
| `tests/Feature/API/V1/Media/ChatControllerTest.php` | 新增 session 相關測試案例 |

---

## Task 1：Resources 與 OpenAPI Schemas

**Files:**
- Create: `app/Http/Resources/ChatMessageResource.php`
- Create: `app/Http/Resources/ChatSessionResource.php`
- Create: `app/Http/Resources/ChatSessionDetailResource.php`
- Create: `app/OpenApi/Schemas/ChatMessageSchema.php`
- Create: `app/OpenApi/Schemas/ChatSessionSchema.php`
- Create: `app/OpenApi/Schemas/ChatSessionDetailSchema.php`

- [ ] **Step 1: 建立 ChatMessageResource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [
            'id'         => strval($this->resource->id),
            'role'       => strval($this->resource->getAttribute('role')),
            'content'    => strval($this->resource->getAttribute('content')),
            'created_at' => strval($this->resource->getAttribute('created_at')),
        ];
    }
}
```

- [ ] **Step 2: 建立 ChatSessionResource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class ChatSessionResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [
            'id'         => strval($this->resource->id),
            'title'      => strval($this->resource->getAttribute('title') ?? ''),
            'created_at' => strval($this->resource->getAttribute('created_at')),
            'updated_at' => strval($this->resource->getAttribute('updated_at')),
        ];
    }
}
```

- [ ] **Step 3: 建立 ChatSessionDetailResource**

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class ChatSessionDetailResource extends JsonResource
{
    public ?string $wrap = null;

    public function toArray(): array
    {
        return [
            'id'         => strval($this->resource->id),
            'title'      => strval($this->resource->getAttribute('title') ?? ''),
            'created_at' => strval($this->resource->getAttribute('created_at')),
            'updated_at' => strval($this->resource->getAttribute('updated_at')),
            'messages'   => ChatMessageResource::collection($this->resource->messages),
        ];
    }
}
```

- [ ] **Step 4: 建立 OpenAPI Schema — ChatMessageSchema**

```php
<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'ChatMessage',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'role', type: 'string', enum: ['user', 'ai'], example: 'user'),
        new OAT\Property(property: 'content', type: 'string', example: 'What is this video about?'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-29T10:00:00Z'),
    ],
    type: 'object'
)]
class ChatMessageSchema
{
}
```

- [ ] **Step 5: 建立 OpenAPI Schema — ChatSessionSchema**

```php
<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'ChatSession',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'title', type: 'string', example: 'What is this video about?'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-29T10:00:00Z'),
        new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-05-29T10:05:00Z'),
    ],
    type: 'object'
)]
class ChatSessionSchema
{
}
```

- [ ] **Step 6: 建立 OpenAPI Schema — ChatSessionDetailSchema**

```php
<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OAT;

#[OAT\Schema(
    schema: 'ChatSessionDetail',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'title', type: 'string', example: 'What is this video about?'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-29T10:00:00Z'),
        new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-05-29T10:05:00Z'),
        new OAT\Property(
            property: 'messages',
            type: 'array',
            items: new OAT\Items(ref: ChatMessageSchema::class)
        ),
    ],
    type: 'object'
)]
class ChatSessionDetailSchema
{
}
```

- [ ] **Step 7: PHPStan 驗證**

```bash
docker compose exec hypervel composer analyse -- \
  app/Http/Resources/ChatMessageResource.php \
  app/Http/Resources/ChatSessionResource.php \
  app/Http/Resources/ChatSessionDetailResource.php \
  app/OpenApi/Schemas/ChatMessageSchema.php \
  app/OpenApi/Schemas/ChatSessionSchema.php \
  app/OpenApi/Schemas/ChatSessionDetailSchema.php
```

期望：`[OK] No errors`

- [ ] **Step 8: Commit**

```bash
git add \
  app/Http/Resources/ChatMessageResource.php \
  app/Http/Resources/ChatSessionResource.php \
  app/Http/Resources/ChatSessionDetailResource.php \
  app/OpenApi/Schemas/ChatMessageSchema.php \
  app/OpenApi/Schemas/ChatSessionSchema.php \
  app/OpenApi/Schemas/ChatSessionDetailSchema.php
git commit -m "$(cat <<'EOF'
feat(chat): 新增 ChatSession / ChatMessage Resources 與 OpenAPI Schema

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2：ChatValidator 新增 session_id 驗證

**Files:**
- Modify: `app/Validators/ChatValidator.php`
- Modify: `tests/Feature/API/V1/Media/ChatControllerTest.php`

- [ ] **Step 1: 在 ChatControllerTest 新增驗證失敗測試**

在 `ChatControllerTest` 的 `testStoreValidatesMessages()` 之後插入：

```php
/**
 * 傳入非 ULID 格式的 session_id → 422.
 */
public function testStoreValidatesSessionIdFormat(): void
{
    /** @var User $user */
    $user   = $this->fakeLogin();
    $source = Source::factory()->create(['free' => true]);
    $media  = Media::factory()->create(['source_id' => $source->id]);
    $uri    = route('api.v1.media.chat.store', ['mediaId' => $media->id]);

    $this->json('POST', $uri, [
        'session_id' => 'not-a-ulid',
        'messages'   => [['role' => 'user', 'content' => 'hi']],
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['messages' => ['session_id']]);
}
```

- [ ] **Step 2: 執行測試確認失敗**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  --filter testStoreValidatesSessionIdFormat
```

期望：FAIL（session_id 目前無驗證規則）

- [ ] **Step 3: 修改 ChatValidator — 加入 session_id 規則與錯誤訊息**

`setStoreRules()` 改為：

```php
public function setStoreRules(): self
{
    $this->rules = [
        'session_id'         => ['nullable', 'string', 'regex:/^[0-7][0-9a-hjkmnp-tv-z]{25}$/'],
        'messages'           => 'required|array|min:1',
        'messages.*.role'    => 'required|string|in:user,assistant,system',
        'messages.*.content' => 'required|string',
    ];

    return $this;
}
```

在 `__construct` 的 `$this->messages` 加入：

```php
'session_id.regex' => __('validators.chat.session_id.invalid'),
```

- [ ] **Step 4: 新增翻譯 key**

`lang/zh_TW/validators.php` 的 `chat` 區塊加入：

```php
'session_id' => [
    'invalid' => 'Session ID 格式不正確',
],
```

`lang/zh_CN/validators.php` 的 `chat` 區塊加入：

```php
'session_id' => [
    'invalid' => 'Session ID 格式不正确',
],
```

`lang/en/validators.php` 的 `chat` 區塊加入：

```php
'session_id' => [
    'invalid' => 'The session ID format is invalid.',
],
```

- [ ] **Step 5: 執行測試確認通過**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  --filter testStoreValidatesSessionIdFormat
```

期望：PASS

- [ ] **Step 6: Commit**

```bash
git add \
  app/Validators/ChatValidator.php \
  lang/zh_TW/validators.php \
  lang/zh_CN/validators.php \
  lang/en/validators.php \
  tests/Feature/API/V1/Media/ChatControllerTest.php
git commit -m "$(cat <<'EOF'
feat(chat): ChatValidator 新增 optional session_id ULID 格式驗證

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3：修改 store() — session 自動建立與訊息保存

**Files:**
- Modify: `app/Http/Controllers/API/V1/Media/ChatController.php`
- Modify: `tests/Feature/API/V1/Media/ChatControllerTest.php`

- [ ] **Step 1: 新增失敗測試 — store() 自動建立 session 並保存訊息**

在 `ChatControllerTest` 新增：

```php
/**
 * 不傳 session_id → 自動建立 ChatSession，保存 user 與 AI 訊息。
 */
public function testStoreCreatesSessionAndSavesMessages(): void
{
    /** @var User $user */
    $user   = $this->fakeLogin();
    $source = Source::factory()->create(['free' => true]);
    $media  = Media::factory()->create(['source_id' => $source->id]);

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
    $user    = $this->fakeLogin();
    $source  = Source::factory()->create(['free' => true]);
    $media   = Media::factory()->create(['source_id' => $source->id]);
    $session = \App\Models\ChatSession::create([
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
}

/**
 * 傳入不屬於此 user 的 session_id → 404.
 */
public function testStoreReturns404ForSessionNotOwnedByUser(): void
{
    $this->fakeLogin();
    $other   = User::factory()->create();
    $source  = Source::factory()->create(['free' => true]);
    $media   = Media::factory()->create(['source_id' => $source->id]);
    $session = \App\Models\ChatSession::create([
        'user_id'  => $other->id,
        'media_id' => $media->id,
    ]);

    $this->json('POST', route('api.v1.media.chat.store', ['mediaId' => $media->id]), [
        'session_id' => $session->id,
        'messages'   => [['role' => 'user', 'content' => 'hi']],
    ])->assertStatus(404);
}
```

- [ ] **Step 2: 執行測試確認失敗**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  --filter "testStoreCreatesSessionAndSavesMessages|testStoreContinuesExistingSession|testStoreReturns404ForSessionNotOwnedByUser"
```

期望：三個都 FAIL

- [ ] **Step 3: 修改 ChatController — 加入 store() 的 session + 訊息邏輯**

在 `use` 區塊加入：

```php
use App\Models\ChatSession;
use App\Models\ChatMessage;
```

新增兩個 private method（放在 `resolveMedia()` 之後）：

```php
/**
 * 找到或建立 ChatSession。
 * session_id 有傳 → 驗證所有權；未傳 → 自動建立。
 *
 * @throws NotFoundHttpException
 */
private function findOrCreateSession(string $userId, string $mediaId, ?string $sessionId, string $userMessage): ChatSession
{
    if ($sessionId !== null) {
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', $userId)
            ->where('media_id', $mediaId)
            ->first();

        if (!$session) {
            throw new NotFoundHttpException();
        }

        return $session;
    }

    $title = mb_substr($userMessage, 0, 50);

    return ChatSession::create([
        'user_id'  => $userId,
        'media_id' => $mediaId,
        'title'    => $title,
    ]);
}

private function saveMessage(string $sessionId, string $role, string $content): void
{
    if ($content === '') {
        return;
    }

    ChatMessage::create([
        'session_id' => $sessionId,
        'role'       => $role,
        'content'    => $content,
        'created_at' => now(),
    ]);
}
```

- [ ] **Step 4: 修改 store() — 加入 session_id 參數、訊息保存、buffer 累積**

將 `store()` 的 `$params = $request->only(['messages'])` 改為：

```php
$params = $request->only(['session_id', 'messages']);
```

在 `$media = $this->resolveMedia(...)` 之後，AI 呼叫之前加入：

```php
$userMessage = collect($params['messages'])->last()['content'] ?? '';
$session = $this->findOrCreateSession(
    $userId,
    $mediaId,
    $params['session_id'] ?? null,
    $userMessage
);
$this->saveMessage($session->id, ChatMessage::ROLE_USER, $userMessage);
$buffer = '';
```

在 streaming 迴圈中，找到這段：

```php
if ($token !== null) {
    Event::dispatch(new ChatTokenEvent($token, $userId, $mediaId));
}
```

改為：

```php
if ($token !== null) {
    $buffer .= $token;
    Event::dispatch(new ChatTokenEvent($token, $userId, $mediaId));
}
```

在 `[DONE]` 分支中，找到：

```php
if ($data === '[DONE]') {
    Event::dispatch(new ChatDoneEvent($userId, $mediaId));

    return response()->json(['status' => 'done']);
}
```

改為：

```php
if ($data === '[DONE]') {
    $this->saveMessage($session->id, ChatMessage::ROLE_AI, $buffer);
    Event::dispatch(new ChatDoneEvent($userId, $mediaId));

    return response()->json(['status' => 'done', 'session_id' => $session->id]);
}
```

在 `store()` 最底部的 fallback return 改為：

```php
$this->saveMessage($session->id, ChatMessage::ROLE_AI, $buffer);
Event::dispatch(new ChatDoneEvent($userId, $mediaId));

return response()->json(['status' => 'done', 'session_id' => $session->id]);
```

同時，移除 `store()` 中這行（已移至下方處理）：

```php
$userMessage = collect($params['messages'])->last()['content'] ?? '';
```

（確保 `$userMessage` 只宣告一次，放在 `findOrCreateSession` 呼叫前）

- [ ] **Step 5: 執行測試確認通過**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  --filter "testStoreCreatesSessionAndSavesMessages|testStoreContinuesExistingSession|testStoreReturns404ForSessionNotOwnedByUser"
```

期望：三個都 PASS

- [ ] **Step 6: 執行完整 ChatControllerTest 確認無退步**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  tests/Feature/API/V1/Media/ChatControllerTest.php
```

期望：全部 PASS

- [ ] **Step 7: PHPStan**

```bash
docker compose exec hypervel composer analyse -- \
  app/Http/Controllers/API/V1/Media/ChatController.php
```

期望：`[OK] No errors`

- [ ] **Step 8: Commit**

```bash
git add \
  app/Http/Controllers/API/V1/Media/ChatController.php \
  app/Validators/ChatValidator.php \
  tests/Feature/API/V1/Media/ChatControllerTest.php
git commit -m "$(cat <<'EOF'
feat(chat): store() 加入 session 自動建立與 user/AI 訊息保存

- 可選 session_id：不傳則建立新 session，傳入則繼續既有 session
- token buffer 累積後於 [DONE] 整體寫入 ChatMessage
- response 新增 session_id 欄位

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4：新增 sessions() 清單端點

**Files:**
- Modify: `app/Http/Controllers/API/V1/Media/ChatController.php`
- Modify: `routes/v1.php`
- Modify: `tests/Feature/API/V1/Media/ChatControllerTest.php`

- [ ] **Step 1: 新增路由（先加路由讓測試可以用 route() helper）**

在 `routes/v1.php` 的 chat group 中，在兩條既有路由之後加入：

```php
Route::get('/sessions', [
    'as'         => 'sessions.index',
    'uses'       => ChatController::class . '@sessions',
    'middleware' => ['auth'],
]);
Route::get(
    '/sessions/{sessionId:[0-7][0-9a-hjkmnp-tv-z]{25}}',
    [
        'as'         => 'sessions.show',
        'uses'       => ChatController::class . '@sessionShow',
        'middleware' => ['auth'],
    ]
);
```

- [ ] **Step 2: 新增 sessions() 失敗測試**

在 `ChatControllerTest` 新增：

```php
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
```

- [ ] **Step 3: 執行失敗測試確認**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  --filter "testSessionsIndexRequiresAuth|testSessionsIndexReturns404WhenNoAccess|testSessionsIndexReturnsUserSessions"
```

期望：FAIL（method sessions() 不存在）

- [ ] **Step 4: 實作 sessions() 方法**

在 ChatController 的 `use` 區塊加入：

```php
use App\Http\Resources\ChatSessionResource;
use App\Http\Resources\ChatSessionDetailResource;
use App\OpenApi\Schemas\ChatSessionSchema;
use App\OpenApi\Schemas\ChatSessionDetailSchema;
use App\OpenApi\Schemas\Paginators;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;
```

新增方法（加在 `sessionShow()` 之前）：

```php
#[OAT\Get(
    path: '/v1/media/{mediaId}/chat/sessions',
    operationId: 'api.v1.media.chat.sessions.index',
    summary: 'List chat sessions for a media',
    security: [['bearerAuth' => []]],
    tags: ['Media'],
    parameters: [
        new OAT\Parameter(ref: MediaId::class),
    ],
    responses: [
        new OAT\Response(
            response: 200,
            description: 'Successful operation',
            content: new OAT\JsonContent(
                properties: [
                    new OAT\Property(
                        property: 'data',
                        type: 'array',
                        items: new OAT\Items(ref: ChatSessionSchema::class)
                    ),
                    new OAT\Property(property: 'links', ref: Paginators\Links::class),
                    new OAT\Property(property: 'meta', ref: Paginators\Meta::class),
                ]
            )
        ),
        new OAT\Response(ref: Http401::class, response: 401),
        new OAT\Response(ref: Http404::class, response: 404),
    ]
)]
public function sessions(Request $request, string $mediaId): AnonymousResourceCollection
{
    $this->resolveMedia($request, $mediaId);

    $userId = (string) $request->user()->getKey();

    $sessions = ChatSession::where('user_id', $userId)
        ->where('media_id', $mediaId)
        ->orderByDesc('updated_at')
        ->paginate(20);

    return ChatSessionResource::collection($sessions);
}
```

- [ ] **Step 5: 執行測試確認通過**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  --filter "testSessionsIndexRequiresAuth|testSessionsIndexReturns404WhenNoAccess|testSessionsIndexReturnsUserSessions"
```

期望：全部 PASS

---

## Task 5：新增 sessionShow() 詳情端點

**Files:**
- Modify: `app/Http/Controllers/API/V1/Media/ChatController.php`
- Modify: `tests/Feature/API/V1/Media/ChatControllerTest.php`

- [ ] **Step 1: 新增失敗測試**

```php
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
```

- [ ] **Step 2: 執行失敗測試確認**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  --filter "testSessionShowRequiresAuth|testSessionShowReturns404WhenNotOwned|testSessionShowReturnsSessionWithMessages"
```

期望：FAIL

- [ ] **Step 3: 實作 sessionShow() 方法**

```php
#[OAT\Get(
    path: '/v1/media/{mediaId}/chat/sessions/{sessionId}',
    operationId: 'api.v1.media.chat.sessions.show',
    summary: 'Get a chat session with its messages',
    security: [['bearerAuth' => []]],
    tags: ['Media'],
    parameters: [
        new OAT\Parameter(ref: MediaId::class),
        new OAT\Parameter(
            name: 'sessionId',
            in: 'path',
            required: true,
            schema: new OAT\Schema(type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ')
        ),
    ],
    responses: [
        new OAT\Response(
            response: 200,
            description: 'Successful operation',
            content: new OAT\JsonContent(ref: ChatSessionDetailSchema::class)
        ),
        new OAT\Response(ref: Http401::class, response: 401),
        new OAT\Response(ref: Http404::class, response: 404),
    ]
)]
public function sessionShow(Request $request, string $mediaId, string $sessionId): ChatSessionDetailResource
{
    $this->resolveMedia($request, $mediaId);

    $userId = (string) $request->user()->getKey();

    $session = ChatSession::with('messages')
        ->where('id', $sessionId)
        ->where('user_id', $userId)
        ->where('media_id', $mediaId)
        ->first();

    if (!$session) {
        throw new NotFoundHttpException();
    }

    return new ChatSessionDetailResource($session);
}
```

- [ ] **Step 4: 執行測試確認通過**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  --filter "testSessionShowRequiresAuth|testSessionShowReturns404WhenNotOwned|testSessionShowReturnsSessionWithMessages"
```

期望：全部 PASS

- [ ] **Step 5: 執行完整 ChatControllerTest**

```bash
docker compose exec hypervel vendor/bin/phpunit \
  tests/Feature/API/V1/Media/ChatControllerTest.php
```

期望：全部 PASS

- [ ] **Step 6: PHPStan**

```bash
docker compose exec hypervel composer analyse -- \
  app/Http/Controllers/API/V1/Media/ChatController.php \
  app/Http/Resources/ChatMessageResource.php \
  app/Http/Resources/ChatSessionResource.php \
  app/Http/Resources/ChatSessionDetailResource.php
```

期望：`[OK] No errors`

- [ ] **Step 7: Commit**

```bash
git add \
  app/Http/Controllers/API/V1/Media/ChatController.php \
  app/Http/Resources/ChatMessageResource.php \
  app/Http/Resources/ChatSessionResource.php \
  app/Http/Resources/ChatSessionDetailResource.php \
  app/OpenApi/Schemas/ChatMessageSchema.php \
  app/OpenApi/Schemas/ChatSessionSchema.php \
  app/OpenApi/Schemas/ChatSessionDetailSchema.php \
  app/Validators/ChatValidator.php \
  lang/zh_TW/validators.php \
  lang/zh_CN/validators.php \
  lang/en/validators.php \
  routes/v1.php \
  tests/Feature/API/V1/Media/ChatControllerTest.php
git commit -m "$(cat <<'EOF'
feat(chat): 新增 chat session 清單與詳情端點

- GET /media/{mediaId}/chat/sessions — 分頁列出此 user 的 sessions
- GET /media/{mediaId}/chat/sessions/{sessionId} — 含完整訊息記錄

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review Checklist

- [x] **Spec coverage**
  - ✅ store() optional session_id → Task 2, 3
  - ✅ 自動建立 session，title 取前 50 字 → Task 3 Step 4
  - ✅ 保存 user message → Task 3 Step 4 (`saveMessage ROLE_USER`)
  - ✅ 保存 AI response（token 累積後入庫）→ Task 3 Step 4 (`buffer`)
  - ✅ response 新增 session_id → Task 3 Step 4
  - ✅ GET sessions → Task 4
  - ✅ GET session detail with messages → Task 5
  - ✅ 錯誤處理：非法 session_id 格式 → Task 2；他人 session → Task 3, 5
  - ✅ AI buffer 空時不入庫 → `saveMessage()` 開頭 `if ($content === '') return`

- [x] **Placeholder scan**：無 TBD / TODO

- [x] **Type consistency**
  - `ChatMessage::ROLE_USER = 'user'`、`ROLE_AI = 'ai'` — model 已定義，Task 3 使用相同常數
  - `findOrCreateSession()` 回傳 `ChatSession`，Task 3 Step 4 用 `$session->id` — 一致
  - `ChatSessionDetailResource` 使用 `$this->resource->messages`，`ChatSession::messages()` HasMany — 一致
