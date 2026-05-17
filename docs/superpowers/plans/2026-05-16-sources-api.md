# Sources API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement four REST endpoints (`GET/POST /v1/sources`, `PUT/DELETE /v1/sources/{id}`) that drive the `SourcesView.vue` UI — listing subscribed YouTube channels/playlists, subscribing with a notify toggle, updating the notify flag, and unsubscribing.

**Architecture:** The `sources` and `user_sources` tables (from the 2026-05-12 schema plan) are already in place; `Source` and `UserSource` models exist. The only schema change is adding a `notify` boolean to `user_sources`. A new `SourcesController` (separate from the existing `RSSController`) handles all four endpoints, using `YoutubeService` to resolve URLs on POST.

**Tech Stack:** Hypervel (Laravel-style), PHP 8.3, `App\Services\YoutubeService`, `UlidBelongsToMany` pivot relation, PHPUnit, SQLite (tests).

---

## File Map

**New migrations:**
- `database/migrations/2026_05_16_100000_add_notify_to_user_sources_table.php`

**Modified models:**
- `app/Models/UserSource.php` — add `notify` to `$fillable`, `$casts`
- `app/Models/User.php` — add `->withPivot('notify')` to `sources()` relationship

**New application files:**
- `app/Validators/SourceValidator.php`
- `app/Http/Resources/SourceResource.php`
- `app/Http/Controllers/API/V1/SourcesController.php`

**Modified files:**
- `routes/v1.php` — add sources route group
- `lang/zh_TW/validators.php` — add `controllers.sources.*` entries
- `lang/zh_CN/validators.php` — add `controllers.sources.*` entries
- `lang/en/validators.php` — add `controllers.sources.*` entries

**New tests:**
- `tests/Feature/API/V1/SourcesControllerTest.php`

---

## Task 1: Add `notify` to `user_sources` Table

The `user_sources` table is missing the `notify` boolean. This task adds it via migration and updates the `UserSource` model and `User.sources()` relationship so pivot data is always loaded.

**Files:**
- Create: `database/migrations/2026_05_16_100000_add_notify_to_user_sources_table.php`
- Modify: `app/Models/UserSource.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Create the migration**

```php
<?php
// database/migrations/2026_05_16_100000_add_notify_to_user_sources_table.php
declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use App\Utils\BaseMigration;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('user_sources', function (Blueprint $table) {
            $table->boolean('notify')->default(true)->after('source_id')->comment('是否開啟信件通知');
        });
    }

    public function down(): void
    {
        Schema::table('user_sources', function (Blueprint $table) {
            $table->dropColumn('notify');
        });
    }
};
```

- [ ] **Step 2: Update `UserSource` model**

In `app/Models/UserSource.php`, replace the current `$fillable` and `$casts`:

```php
protected array $fillable = [
    'user_id',
    'source_id',
    'notify',
];

protected array $casts = [
    'notify' => 'boolean',
];
```

- [ ] **Step 3: Add `->withPivot('notify')` to `User.sources()`**

In `app/Models/User.php`, update the `sources()` method — change the final chain from `->withTimestamps()` to `->withTimestamps()->withPivot('notify')`:

```php
public function sources(): UlidBelongsToMany
{
    $instance = $this->newRelatedInstance(Source::class);

    return (new UlidBelongsToMany(
        $instance->newQuery(),
        $this,
        'user_sources',
        'user_id',
        'source_id',
        $this->getKeyName(),
        $instance->getKeyName(),
        'sources'
    ))->withTimestamps()->withPivot('notify');
}
```

- [ ] **Step 4: Run migration and full test suite**

```bash
docker compose exec hypervel vendor/bin/phpunit -v
```

Expected: all previously passing tests still pass (migration is additive).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_16_100000_add_notify_to_user_sources_table.php \
        app/Models/UserSource.php \
        app/Models/User.php
git commit -m "feat(sources): add notify column to user_sources table"
```

---

## Task 2: Write Failing `SourcesControllerTest`

Write the complete test file before any controller code exists. All tests must fail at this point — that's the intended state.

**Files:**
- Create: `tests/Feature/API/V1/SourcesControllerTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php
// tests/Feature/API/V1/SourcesControllerTest.php
declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use Mockery\MockInterface;
use App\Services\YoutubeService;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class SourcesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testIndex(): void
    {
        $uri = route('api.v1.sources.index');

        $this->json('GET', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // Empty list when no sources subscribed
        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // Subscribe to a channel
        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'title'       => 'Test Channel',
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
        ]);
        $user->sources()->attach($source->id, ['notify' => true]);

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $source->id)
            ->assertJsonPath('data.0.name', 'Test Channel')
            ->assertJsonPath('data.0.type', 'channel')
            ->assertJsonPath('data.0.notify', true);

        // Subscribe to a playlist
        $playlist = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_PLAYLIST,
            'title'       => 'Test Playlist',
            'external_id' => 'PLxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ]);
        $user->sources()->attach($playlist->id, ['notify' => false]);

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function testStoreChannel(): void
    {
        $uri = route('api.v1.sources.store');

        $this->json('POST', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // Missing required fields
        $this->json('POST', $uri)
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['url', 'type']]);

        // Invalid type
        $this->json('POST', $uri, ['url' => 'https://www.youtube.com/@test', 'type' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['type']]);

        // Invalid URL (YoutubeService returns null channel ID)
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn(null);
        });

        $this->json('POST', $uri, ['url' => 'https://invalid-url.com', 'type' => 'channel'])
            ->assertStatus(422)
            ->assertJsonPath('messages.url.0', __('validators.controllers.sources.invalid_url'));

        // Valid channel URL
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn('UCxxxxxxxxxxxxxxxxxxxxxx');
        });

        Http::fake([
            'www.youtube.com/feeds/videos.xml*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Test Channel</title></feed>',
                200
            ),
        ]);

        $response = $this->json('POST', $uri, [
            'url'    => 'https://www.youtube.com/@testchannel',
            'type'   => 'channel',
            'notify' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'url', 'type', 'notify'])
            ->assertJsonPath('type', 'channel')
            ->assertJsonPath('notify', true)
            ->assertJsonPath('name', 'Test Channel');

        $sourceId = $response->json('id');

        $this->assertDatabaseHas('sources', [
            'id'          => $sourceId,
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
        ]);

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $sourceId,
            'notify'    => true,
        ]);

        // Re-subscribing the same source updates notify and does not duplicate
        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getChannelIdFromUrl')->andReturn('UCxxxxxxxxxxxxxxxxxxxxxx');
        });

        Http::fake([
            'www.youtube.com/feeds/videos.xml*' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Test Channel</title></feed>',
                200
            ),
        ]);

        $this->json('POST', $uri, [
            'url'    => 'https://www.youtube.com/@testchannel',
            'type'   => 'channel',
            'notify' => false,
        ])->assertStatus(201);

        $this->assertEquals(1, $user->sources()->count());

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $sourceId,
            'notify'    => false,
        ]);
    }

    public function testStorePlaylist(): void
    {
        $uri = route('api.v1.sources.store');

        /** @var User $user */
        $user = $this->fakeLogin();

        $playlistId = 'PLu96Vzt7fGU7IC5vsXXKgUWwASv5FTWMx';

        $this->mock(YoutubeService::class, function (MockInterface $mock) use ($playlistId) {
            $mock->shouldReceive('getPlaylistIdFromUrl')->andReturn($playlistId);
            $mock->shouldReceive('getPlaylistDetails')->with($playlistId)->andReturn([
                'title'         => 'Test Playlist',
                'channel_id'    => 'UCxxxxxx',
                'channel_title' => 'Some Channel',
            ]);
        });

        $response = $this->json('POST', $uri, [
            'url'    => 'https://www.youtube.com/playlist?list=' . $playlistId,
            'type'   => 'playlist',
            'notify' => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('type', 'playlist')
            ->assertJsonPath('notify', false)
            ->assertJsonPath('name', 'Test Playlist');

        $sourceId = $response->json('id');

        $this->assertDatabaseHas('sources', [
            'id'          => $sourceId,
            'type'        => Source::TYPE_YOUTUBE_PLAYLIST,
            'external_id' => $playlistId,
        ]);

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $sourceId,
            'notify'    => false,
        ]);
    }

    public function testUpdate(): void
    {
        /** @var User $user */
        $user   = $this->fakeLogin();
        $source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL]);
        $user->sources()->attach($source->id, ['notify' => true]);

        $uri = route('api.v1.sources.update', ['sourceId' => $source->id]);

        // Missing notify field
        $this->json('PUT', $uri, [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['notify']]);

        // Toggle off
        $this->json('PUT', $uri, ['notify' => false])
            ->assertStatus(200)
            ->assertJsonPath('notify', false);

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $source->id,
            'notify'    => false,
        ]);

        // Toggle back on
        $this->json('PUT', $uri, ['notify' => true])
            ->assertStatus(200)
            ->assertJsonPath('notify', true);

        // Cannot update a source the user is not subscribed to
        $otherSource = Source::factory()->create();
        $otherUri    = route('api.v1.sources.update', ['sourceId' => $otherSource->id]);

        $this->json('PUT', $otherUri, ['notify' => false])->assertStatus(404);
    }

    public function testDestroy(): void
    {
        $source = Source::factory()->create();
        $uri    = route('api.v1.sources.destroy', ['sourceId' => $source->id]);

        $this->json('DELETE', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();
        $user->sources()->attach($source->id, ['notify' => true]);

        $this->json('DELETE', $uri)->assertStatus(200);

        $this->assertDatabaseMissing('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $source->id,
        ]);

        // The shared sources record itself still exists
        $this->assertDatabaseHas('sources', ['id' => $source->id]);

        // Cannot delete a source the user is not subscribed to
        $otherSource = Source::factory()->create();
        $otherUri    = route('api.v1.sources.destroy', ['sourceId' => $otherSource->id]);

        $this->json('DELETE', $otherUri)->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run tests to verify all 5 fail**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/API/V1/SourcesControllerTest.php -v
```

Expected: FAIL — `Route [api.v1.sources.index] not defined` or class-not-found error on every test.

- [ ] **Step 3: Commit the failing test file**

```bash
git add tests/Feature/API/V1/SourcesControllerTest.php
git commit -m "test(sources): add failing SourcesControllerTest for all 4 endpoints"
```

---

## Task 3: Implement Sources API (Lang + Validator + Resource + Controller + Routes)

Build everything needed to make the tests pass.

**Files:**
- Modify: `lang/zh_TW/validators.php`
- Modify: `lang/zh_CN/validators.php`
- Modify: `lang/en/validators.php`
- Create: `app/Validators/SourceValidator.php`
- Create: `app/Http/Resources/SourceResource.php`
- Create: `app/Http/Controllers/API/V1/SourcesController.php`
- Modify: `routes/v1.php`

- [ ] **Step 1: Add lang strings to all three locales**

In `lang/zh_TW/validators.php`, inside the `'controllers'` array after the `'rss'` block:

```php
'sources' => [
    'invalid_url' => '無效的 YouTube 網址。',
    'not_found'   => '找不到指定的來源。',
],
```

In `lang/zh_CN/validators.php`, same location:

```php
'sources' => [
    'invalid_url' => '无效的 YouTube 网址。',
    'not_found'   => '找不到指定的来源。',
],
```

In `lang/en/validators.php`, same location:

```php
'sources' => [
    'invalid_url' => 'Invalid YouTube URL.',
    'not_found'   => 'Source not found.',
],
```

- [ ] **Step 2: Create `SourceValidator`**

```php
<?php
// app/Validators/SourceValidator.php
declare(strict_types=1);

namespace App\Validators;

class SourceValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);

        $this->messages = [
            'url.required'    => __('validators.source.url.required'),
            'type.required'   => __('validators.source.type.required'),
            'type.in'         => __('validators.source.type.in'),
            'notify.required' => __('validators.source.notify.required'),
            'notify.boolean'  => __('validators.source.notify.boolean'),
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'url'    => 'required|string',
            'type'   => 'required|string|in:channel,playlist',
            'notify' => 'sometimes|boolean',
        ];

        return $this;
    }

    public function setUpdateRules(): self
    {
        $this->rules = [
            'notify' => 'required|boolean',
        ];

        return $this;
    }
}
```

- [ ] **Step 3: Add lang keys for `source` field messages**

In `lang/zh_TW/validators.php`, add a `'source'` section after the `'rss'` section:

```php
'source' => [
    'url' => [
        'required' => 'URL 為必填。',
    ],
    'type' => [
        'required' => '類型為必填。',
        'in'       => '類型必須是 channel 或 playlist。',
    ],
    'notify' => [
        'required' => '通知設定為必填。',
        'boolean'  => '通知設定必須是布林值。',
    ],
],
```

In `lang/zh_CN/validators.php`:

```php
'source' => [
    'url' => [
        'required' => 'URL 为必填。',
    ],
    'type' => [
        'required' => '类型为必填。',
        'in'       => '类型必须是 channel 或 playlist。',
    ],
    'notify' => [
        'required' => '通知设置为必填。',
        'boolean'  => '通知设置必须是布尔值。',
    ],
],
```

In `lang/en/validators.php`:

```php
'source' => [
    'url' => [
        'required' => 'URL is required.',
    ],
    'type' => [
        'required' => 'Type is required.',
        'in'       => 'Type must be channel or playlist.',
    ],
    'notify' => [
        'required' => 'Notify setting is required.',
        'boolean'  => 'Notify setting must be a boolean.',
    ],
],
```

- [ ] **Step 4: Create `SourceResource`**

```php
<?php
// app/Http/Resources/SourceResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Source;
use Hypervel\Http\Resources\Json\JsonResource;

class SourceResource extends JsonResource
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
            'id'     => strval($this->resource->id),
            'name'   => strval($this->resource->title ?? ''),
            'url'    => $this->resolveDisplayUrl(),
            'type'   => $this->resource->type === Source::TYPE_YOUTUBE_CHANNEL ? 'channel' : 'playlist',
            'notify' => (bool) ($this->resource->pivot?->notify ?? true),
        ];
    }
}
```

- [ ] **Step 5: Create `SourcesController`**

```php
<?php
// app/Http/Controllers/API/V1/SourcesController.php
declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Source;
use Hypervel\Http\Request;
use App\Services\YoutubeService;
use Hypervel\Support\Facades\Http;
use Hypervel\HttpClient\ConnectionException;
use App\Validators\SourceValidator;
use App\Http\Resources\SourceResource;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use Hypervel\Http\Resources\Json\ResourceCollection;

class SourcesController extends AbstractController
{
    public function index(Request $request): ResourceCollection
    {
        return SourceResource::collection(
            $request->user()->sources()->get()
        );
    }

    /**
     * @throws InvalidRequestException
     */
    public function store(Request $request): SourceResource
    {
        $params = $request->only(['url', 'type', 'notify']);

        $v = new SourceValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $notify         = (bool) ($params['notify'] ?? true);
        $youtubeService = app(YoutubeService::class);

        if ($params['type'] === 'channel') {
            [$externalId, $title, $dbType] = $this->resolveChannel($params['url'], $youtubeService);
        } else {
            [$externalId, $title, $dbType] = $this->resolvePlaylist($params['url'], $youtubeService);
        }

        $source = Source::firstOrCreate(
            ['external_id' => $externalId, 'type' => $dbType],
            ['title' => $title, 'url' => $this->buildRssUrl($dbType, $externalId), 'status' => Source::STATUS_ACTIVE]
        );

        if ($request->user()->sources()->find($source->id)) {
            $request->user()->sources()->updateExistingPivot($source->id, ['notify' => $notify]);
        } else {
            $request->user()->sources()->attach($source->id, ['notify' => $notify]);
        }

        return new SourceResource(
            $request->user()->sources()->find($source->id)
        );
    }

    /**
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
     */
    public function update(Request $request, string $sourceId): SourceResource
    {
        $params = $request->only(['notify']);

        $v = new SourceValidator($params);
        $v->setUpdateRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$source = $request->user()->sources()->find($sourceId)) {
            throw new NotFoundHttpException();
        }

        $request->user()->sources()->updateExistingPivot($sourceId, ['notify' => (bool) $params['notify']]);

        return new SourceResource(
            $request->user()->sources()->find($sourceId)
        );
    }

    /**
     * @throws NotFoundHttpException
     */
    public function destroy(Request $request, string $sourceId): ResponseInterface
    {
        if (!$request->user()->sources()->find($sourceId)) {
            throw new NotFoundHttpException();
        }

        $request->user()->sources()->detach($sourceId);

        return response()->make(self::RESPONSE_OK);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     * @throws InvalidRequestException
     */
    private function resolveChannel(string $url, YoutubeService $youtubeService): array
    {
        $channelId = $youtubeService->getChannelIdFromUrl($url);

        if (!$channelId) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        $rssUrl = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId;

        try {
            $response = Http::get($rssUrl);
        } catch (ConnectionException) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        if (!$response->successful()) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        return [$channelId, (string) ($xml->title ?? 'No Title'), Source::TYPE_YOUTUBE_CHANNEL];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     * @throws InvalidRequestException
     */
    private function resolvePlaylist(string $url, YoutubeService $youtubeService): array
    {
        $playlistId = $youtubeService->getPlaylistIdFromUrl($url);

        if (!$playlistId) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        $details = $youtubeService->getPlaylistDetails($playlistId);

        if (!$details) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        return [$playlistId, $details['title'], Source::TYPE_YOUTUBE_PLAYLIST];
    }

    private function buildRssUrl(string $dbType, string $externalId): string
    {
        if ($dbType === Source::TYPE_YOUTUBE_CHANNEL) {
            return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $externalId;
        }

        return 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $externalId;
    }
}
```

- [ ] **Step 6: Add sources routes to `routes/v1.php`**

In `routes/v1.php`, after the existing `rss` route group, add:

```php
Route::group('/sources', function () {
    Route::get('/', [SourcesController::class, 'index']);
    Route::post('/', [SourcesController::class, 'store']);
    Route::put('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [SourcesController::class, 'update']);
    Route::delete('/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [SourcesController::class, 'destroy']);
}, ['as' => 'sources']);
```

Also add the `use` import at the top of `routes/v1.php`:

```php
use App\Http\Controllers\API\V1\SourcesController;
```

- [ ] **Step 7: Run the tests to verify all 5 pass**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/API/V1/SourcesControllerTest.php -v
```

Expected: PASS (5 tests — `testIndex`, `testStoreChannel`, `testStorePlaylist`, `testUpdate`, `testDestroy`).

- [ ] **Step 8: Commit**

```bash
git add lang/zh_TW/validators.php \
        lang/zh_CN/validators.php \
        lang/en/validators.php \
        app/Validators/SourceValidator.php \
        app/Http/Resources/SourceResource.php \
        app/Http/Controllers/API/V1/SourcesController.php \
        routes/v1.php
git commit -m "feat(sources): implement GET/POST/PUT/DELETE /v1/sources endpoints"
```

---

## Task 4: Final Integration Verification

- [ ] **Step 1: Run the full test suite**

```bash
docker compose exec hypervel vendor/bin/phpunit -v
```

Expected: all tests pass, no regressions.

- [ ] **Step 2: Run static analysis**

```bash
docker compose exec hypervel composer analyse
```

Expected: no new errors.

- [ ] **Step 3: Run code style fixer**

```bash
docker compose exec hypervel composer cs-diff
```

Fix any reported issues:

```bash
docker compose exec hypervel composer cs-fix \
    app/Validators/SourceValidator.php \
    app/Http/Resources/SourceResource.php \
    app/Http/Controllers/API/V1/SourcesController.php \
    app/Models/UserSource.php \
    app/Models/User.php \
    routes/v1.php \
    lang/zh_TW/validators.php \
    lang/zh_CN/validators.php \
    lang/en/validators.php
```

- [ ] **Step 4: Commit style fixes if any**

```bash
git add -p
git commit -m "style: apply cs-fixer to sources API files"
```

---

## API Contract Summary

| Method | Path | Auth | Request body | Response |
|--------|------|------|-------------|---------|
| GET | `/v1/sources` | required | — | `{ data: SourceResource[] }` |
| POST | `/v1/sources` | required | `{ url, type: "channel"\|"playlist", notify?: bool }` | `SourceResource` (201) |
| PUT | `/v1/sources/{id}` | required | `{ notify: bool }` | `SourceResource` (200) |
| DELETE | `/v1/sources/{id}` | required | — | `"OK."` (200) |

**SourceResource shape:**
```json
{
  "id": "01JXXXXXXXXXXXXXXXXXXXXXXXXX",
  "name": "Channel Title",
  "url": "https://www.youtube.com/channel/UCxxxxxx",
  "type": "channel",
  "notify": true
}
```
