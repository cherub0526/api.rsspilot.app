# Sources Show Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `GET /v1/sources/{sourceId}` that returns detailed information for a single source, accessible to free sources or subscribed users.

**Architecture:** Create a `SourceDetailResource` for the detail response shape, add `show()` to `SourcesController` with access control (free=true OR subscribed), and register the route inside the existing `sources` group. Follow TDD — write the failing test first, then implement.

**Tech Stack:** PHP 8.3, Hypervel (Laravel-style), PHPUnit, Eloquent, swagger-php (OAT Attributes)

**Run all commands inside the Docker container:**
```bash
docker compose exec hypervel <command>
```

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| **Create** | `app/Http/Resources/SourceDetailResource.php` | Detail response shape — 8 fields including `description` and `subscriber_count` |
| **Modify** | `app/Http/Controllers/API/V1/SourcesController.php` | Add `show()` method with OAT annotations |
| **Modify** | `routes/v1.php` | Register `GET /{sourceId}` inside `sources` group |
| **Modify** | `tests/Feature/API/V1/SourcesControllerTest.php` | Add `testShow()` covering all access-control and field cases |

---

## Task 1: Write the failing test

**Files:**
- Modify: `tests/Feature/API/V1/SourcesControllerTest.php`

- [ ] **Step 1: Add `testShow()` to `SourcesControllerTest`**

Open `tests/Feature/API/V1/SourcesControllerTest.php` and append this method before the closing `}` of the class:

```php
public function testShow(): void
{
    $source = Source::factory()->create([
        'type'        => Source::TYPE_YOUTUBE_CHANNEL,
        'title'       => 'Test Channel',
        'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
        'description' => 'Channel about testing.',
        'metadata'    => ['subscriber_count' => 42000],
        'free'        => false,
    ]);

    $uri = route('api.v1.sources.show', ['sourceId' => $source->id]);

    // --- 401 when unauthenticated ---
    $this->json('GET', $uri)->assertStatus(401);

    /** @var User $user */
    $user = $this->fakeLogin();

    // --- 404 when not subscribed and not free ---
    $this->json('GET', $uri)->assertStatus(404);

    // --- 200 when subscribed ---
    $user->sources()->attach($source->id, ['notify' => true]);

    $this->json('GET', $uri)
        ->assertStatus(200)
        ->assertJsonPath('id', strval($source->id))
        ->assertJsonPath('name', 'Test Channel')
        ->assertJsonPath('url', 'https://www.youtube.com/channel/UCxxxxxxxxxxxxxxxxxxxxxx')
        ->assertJsonPath('type', 'channel')
        ->assertJsonPath('notify', true)
        ->assertJsonPath('description', 'Channel about testing.')
        ->assertJsonPath('subscriber_count', 42000);

    // --- subscriber_count falls back to 0 when missing from metadata ---
    $noCountSource = Source::factory()->create([
        'type'        => Source::TYPE_YOUTUBE_CHANNEL,
        'external_id' => 'UCyyyyyyyyyyyyyyyyyyyyyy',
        'metadata'    => [],
        'free'        => false,
    ]);
    $user->sources()->attach($noCountSource->id, ['notify' => false]);

    $this->json('GET', route('api.v1.sources.show', ['sourceId' => $noCountSource->id]))
        ->assertStatus(200)
        ->assertJsonPath('subscriber_count', 0);

    // --- description falls back to "" when null ---
    $noDescSource = Source::factory()->create([
        'type'        => Source::TYPE_YOUTUBE_CHANNEL,
        'external_id' => 'UCzzzzzzzzzzzzzzzzzzzzzz',
        'description' => null,
        'free'        => false,
    ]);
    $user->sources()->attach($noDescSource->id, ['notify' => false]);

    $this->json('GET', route('api.v1.sources.show', ['sourceId' => $noDescSource->id]))
        ->assertStatus(200)
        ->assertJsonPath('description', '');

    // --- 200 for a free source even without subscription ---
    $freeSource = Source::factory()->create([
        'type'        => Source::TYPE_YOUTUBE_CHANNEL,
        'external_id' => 'UCwwwwwwwwwwwwwwwwwwwwww',
        'description' => 'Free channel.',
        'metadata'    => ['subscriber_count' => 5000],
        'free'        => true,
    ]);

    // Log in as a different user with no subscriptions
    $otherUser = $this->fakeLogin();

    $this->json('GET', route('api.v1.sources.show', ['sourceId' => $freeSource->id]))
        ->assertStatus(200)
        ->assertJsonPath('description', 'Free channel.')
        ->assertJsonPath('subscriber_count', 5000)
        ->assertJsonPath('notify', true); // default when no pivot

    // --- playlist URL format ---
    $playlist = Source::factory()->create([
        'type'        => Source::TYPE_YOUTUBE_PLAYLIST,
        'external_id' => 'PLxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx12',
        'free'        => false,
    ]);
    $otherUser->sources()->attach($playlist->id, ['notify' => false]);

    $this->json('GET', route('api.v1.sources.show', ['sourceId' => $playlist->id]))
        ->assertStatus(200)
        ->assertJsonPath('url', 'https://www.youtube.com/playlist?list=PLxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx12')
        ->assertJsonPath('type', 'playlist')
        ->assertJsonPath('subscriber_count', 0);
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit --filter testShow tests/Feature/API/V1/SourcesControllerTest.php
```

Expected: **FAIL** — route `api.v1.sources.show` not defined.

---

## Task 2: Create `SourceDetailResource`

**Files:**
- Create: `app/Http/Resources/SourceDetailResource.php`

- [ ] **Step 3: Create the resource class**

Create `app/Http/Resources/SourceDetailResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Source;
use Hypervel\Http\Resources\Json\JsonResource;

class SourceDetailResource extends JsonResource
{
    public ?string $wrap = null;

    private function resolveDisplayUrl(): string
    {
        if ($this->resource->type === Source::TYPE_YOUTUBE_CHANNEL) {
            return 'https://www.youtube.com/channel/' . $this->resource->external_id;
        }

        return 'https://www.youtube.com/playlist?list=' . $this->resource->external_id;
    }

    public function toArray(): array
    {
        return [
            'id'               => strval($this->resource->id),
            'name'             => strval($this->resource->title ?? ''),
            'url'              => $this->resolveDisplayUrl(),
            'type'             => $this->resource->type === Source::TYPE_YOUTUBE_CHANNEL ? 'channel' : 'playlist',
            'notify'           => (bool) ($this->resource->pivot?->notify ?? true),
            'thumbnail'        => $this->resource->thumbnail,
            'description'      => strval($this->resource->description ?? ''),
            'subscriber_count' => (int) ($this->resource->metadata['subscriber_count'] ?? 0),
        ];
    }
}
```

---

## Task 3: Add `show()` to `SourcesController`

**Files:**
- Modify: `app/Http/Controllers/API/V1/SourcesController.php`

- [ ] **Step 4: Add the `show()` method**

In `app/Http/Controllers/API/V1/SourcesController.php`, add the following `use` import after the existing `use App\Http\Resources\SourceResource;` line:

```php
use App\Http\Resources\SourceDetailResource;
```

Then add the `show()` method after the `index()` method (before `store()`):

```php
#[OAT\Get(
    path: '/v1/sources/{sourceId}',
    operationId: 'api.v1.sources.show',
    summary: 'Get detailed information for a single source',
    security: [['bearerAuth' => []]],
    tags: ['Sources'],
    parameters: [
        new OAT\Parameter(ref: SourceId::class),
    ],
    responses: [
        new OAT\Response(
            response: 200,
            description: 'Successful operation',
            content: new OAT\JsonContent(
                properties: [
                    new OAT\Property(property: 'id', type: 'string', example: '01hwzxxxxxxxxxxxxxxxxxxxxxx'),
                    new OAT\Property(property: 'name', type: 'string', example: 'Google Developers'),
                    new OAT\Property(property: 'url', type: 'string', example: 'https://www.youtube.com/channel/UC295-Dw4tzbkl8M9I2GFRtg'),
                    new OAT\Property(property: 'type', type: 'string', enum: ['channel', 'playlist']),
                    new OAT\Property(property: 'notify', type: 'boolean', example: true),
                    new OAT\Property(property: 'thumbnail', type: 'string', nullable: true),
                    new OAT\Property(property: 'description', type: 'string', example: 'News and tutorials from the Google Developers team.'),
                    new OAT\Property(property: 'subscriber_count', type: 'integer', example: 1230000),
                ]
            )
        ),
        new OAT\Response(ref: Http401::class, response: 401),
        new OAT\Response(ref: Http404::class, response: 404),
    ]
)]
/**
 * @throws NotFoundHttpException
 */
public function show(Request $request, string $sourceId): SourceDetailResource
{
    // Subscribed sources already carry pivot data (notify setting)
    $source = $request->user()->sources()->find($sourceId);

    // Fall back to free sources (no pivot — notify defaults to true in resource)
    if (!$source) {
        $source = Source::query()
            ->where('id', $sourceId)
            ->where('free', true)
            ->first();
    }

    if (!$source) {
        throw new NotFoundHttpException();
    }

    return new SourceDetailResource($source);
}
```

---

## Task 4: Register the route

**Files:**
- Modify: `routes/v1.php`

- [ ] **Step 5: Add the GET `/{sourceId}` route**

In `routes/v1.php`, inside the `sources` route group, add the `show` route **after** the `store` route and **before** the `update` route (line ~146):

```php
Route::get('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
    'as'         => 'show',
    'uses'       => SourcesController::class . '@show',
    'middleware' => ['auth'],
]);
```

The sources group should look like this after the change:

```php
Route::group('/sources', function () {
    Route::get('/', [
        'as'         => 'index',
        'uses'       => SourcesController::class . '@index',
        'middleware' => ['auth'],
    ]);
    Route::post('/', [
        'as'         => 'store',
        'uses'       => SourcesController::class . '@store',
        'middleware' => ['auth'],
    ]);
    Route::get('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
        'as'         => 'show',
        'uses'       => SourcesController::class . '@show',
        'middleware' => ['auth'],
    ]);
    Route::put('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
        'as'         => 'update',
        'uses'       => SourcesController::class . '@update',
        'middleware' => ['auth'],
    ]);
    Route::delete('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
        'as'         => 'destroy',
        'uses'       => SourcesController::class . '@destroy',
        'middleware' => ['auth'],
    ]);

    Route::group('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}/medias', function () {
        Route::get('/', [
            'as'         => 'index',
            'uses'       => SourceMediasController::class . '@index',
            'middleware' => ['auth'],
        ]);
    }, ['as' => 'medias']);
}, ['as' => 'sources']);
```

---

## Task 5: Run tests and commit

- [ ] **Step 6: Run `testShow` to confirm it passes**

```bash
docker compose exec hypervel vendor/bin/phpunit --filter testShow tests/Feature/API/V1/SourcesControllerTest.php
```

Expected: **PASS** — 1 test, no failures.

- [ ] **Step 7: Run full SourcesControllerTest to confirm no regressions**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/API/V1/SourcesControllerTest.php
```

Expected: **PASS** — all existing tests still green.

- [ ] **Step 8: Run static analysis**

```bash
docker compose exec hypervel composer analyse
```

Expected: no errors on the new/modified files.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Resources/SourceDetailResource.php \
        app/Http/Controllers/API/V1/SourcesController.php \
        routes/v1.php \
        tests/Feature/API/V1/SourcesControllerTest.php
git commit -m "$(cat <<'EOF'
feat(sources): 新增取得單一來源詳細資訊的端點

調整項目：
1. 新增 SourceDetailResource（含 description、subscriber_count）
2. 新增 SourcesController@show（free=true 或已訂閱可存取）
3. 新增路由 GET /v1/sources/{sourceId}（需認證）
4. 補充對應測試與 OpenAPI 文件

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Regenerate OpenAPI JSON

- [ ] **Step 10: Regenerate `public/openapi.json`**

```bash
docker compose exec hypervel php artisan openapi:generate
```

- [ ] **Step 11: Commit the updated OpenAPI JSON**

```bash
git add public/openapi.json
git commit -m "$(cat <<'EOF'
docs(openapi): 重新產生 OpenAPI JSON（新增 sources show 端點）

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```
