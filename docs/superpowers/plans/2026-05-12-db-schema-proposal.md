# DB Schema Proposal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the DB schema proposal from `Audistializer/docs/db-schema-proposal.md` — adding new tables (sources, user_sources, watch_history, chat_sessions, chat_messages, summary_configs, summary_config_sources) and extending existing tables (media, summaries, plans, feedbacks, settings).

**Architecture:** New tables are additive and do not rename or drop any existing tables/columns, so the existing RSS/userables/custom_prompts flow continues to work while new models are introduced. Each migration runs independently in timestamp order; models are updated alongside their migrations.

**Tech Stack:** Hypervel (Laravel-style), PHP 8.3, SQLite (tests), MySQL (production), PHPUnit, `App\Utils\BaseMigration`, ULID primary keys, `HasUlids` trait.

---

## File Map

**New migrations:**
- `database/migrations/2026_05_12_100000_create_sources_table.php`
- `database/migrations/2026_05_12_100001_create_user_sources_table.php`
- `database/migrations/2026_05_12_100002_add_source_id_language_to_media_table.php`
- `database/migrations/2026_05_12_100003_create_watch_history_table.php`
- `database/migrations/2026_05_12_100004_create_chat_sessions_table.php`
- `database/migrations/2026_05_12_100005_create_chat_messages_table.php`
- `database/migrations/2026_05_12_100006_create_summary_configs_table.php`
- `database/migrations/2026_05_12_100007_create_summary_config_sources_table.php`
- `database/migrations/2026_05_12_100008_add_columns_to_summaries_table.php`
- `database/migrations/2026_05_12_100009_add_feature_flags_to_plans_table.php`
- `database/migrations/2026_05_12_100010_add_user_id_to_feedbacks_table.php`
- `database/migrations/2026_05_12_100011_add_version_to_settings_table.php`

**New models:**
- `app/Models/Source.php`
- `app/Models/UserSource.php`
- `app/Models/WatchHistory.php`
- `app/Models/ChatSession.php`
- `app/Models/ChatMessage.php`
- `app/Models/SummaryConfig.php`

**New factories:**
- `database/factories/SourceFactory.php`
- `database/factories/WatchHistoryFactory.php`
- `database/factories/ChatSessionFactory.php`
- `database/factories/ChatMessageFactory.php`
- `database/factories/SummaryConfigFactory.php`

**Modified models:**
- `app/Models/Media.php` — add `source_id`, `language` to fillable/casts, add `source()` relationship
- `app/Models/User.php` — add `sources()`, `watchHistory()`, `chatSessions()` relationships
- `app/Models/Summary.php` — add `config_id`, `ai_model`, `prompt_type` to fillable/casts, add `config()` relationship
- `app/Models/Plan.php` — add 4 feature-flag constants + fillable
- `app/Models/Feedback.php` — add `user_id` to fillable, add `user()` relationship
- `app/Models/Setting.php` — add `version` to fillable/casts
- `app/Models/CustomPrompt.php` — fix wrong table name (`custom_settings` → `custom_prompts`)

**New tests:**
- `tests/Feature/Models/SourceTest.php`
- `tests/Feature/Models/UserSourceTest.php`
- `tests/Feature/Models/WatchHistoryTest.php`
- `tests/Feature/Models/ChatSessionTest.php`
- `tests/Feature/Models/SummaryConfigTest.php`
- `tests/Feature/Models/PlanFeatureFlagsTest.php`
- `tests/Feature/Models/FeedbackUserTest.php`

---

## Task 1: sources Table + Source Model

`sources` is the semantic replacement for `rss`. The `rss` table stays untouched.

**Files:**
- Create: `database/migrations/2026_05_12_100000_create_sources_table.php`
- Create: `app/Models/Source.php`
- Create: `database/factories/SourceFactory.php`
- Create: `tests/Feature/Models/SourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Models/SourceTest.php
declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Source;
use Hypervel\Foundation\Testing\RefreshDatabase;

class SourceTest extends TestCase
{
    use RefreshDatabase;

    public function testCanCreateSource(): void
    {
        $source = Source::create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
            'title'       => 'Test Channel',
            'url'         => 'https://www.youtube.com/channel/UCxxxxxxxxxxxxxxxxxxxxxx',
        ]);

        $this->assertDatabaseHas('sources', [
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
        ]);

        $this->assertNotNull($source->id);
    }

    public function testScopeActive(): void
    {
        Source::factory()->create(['status' => Source::STATUS_ACTIVE]);
        Source::factory()->create(['status' => 'inactive']);

        $this->assertCount(1, Source::active()->get());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/SourceTest.php -v
```

Expected: FAIL — `Class "App\Models\Source" not found` or `Table "sources" doesn't exist`.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_05_12_100000_create_sources_table.php
declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type')->index()->comment('類型: youtube_channel | youtube_playlist');
            $table->string('external_id')->index()->comment('外部平台 ID（YouTube channel/playlist ID）');
            $table->string('title')->nullable()->comment('標題');
            $table->string('url', 1024)->comment('網址');
            $table->string('thumbnail')->nullable()->comment('縮圖');
            $table->text('description')->nullable()->comment('描述');
            $table->text('metadata')->nullable()->comment('JSON: subscriber_count, video_count 等');
            $table->timestamp('last_synced_at')->nullable()->index()->comment('最後同步時間');
            $table->string('status')->default('active')->index()->comment('狀態');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('訂閱來源（頻道或播放清單）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
```

- [ ] **Step 4: Create the Source model**

```php
<?php
// app/Models/Source.php
declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\HasMany;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Source extends Model
{
    use HasUlids;
    use SoftDeletes;
    use HasFactory;

    public const string TYPE_YOUTUBE_CHANNEL = 'youtube_channel';
    public const string TYPE_YOUTUBE_PLAYLIST = 'youtube_playlist';

    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    public static array $typeMaps = [
        self::TYPE_YOUTUBE_CHANNEL  => 'YouTube 頻道',
        self::TYPE_YOUTUBE_PLAYLIST => 'YouTube 播放清單',
    ];

    protected ?string $table = 'sources';

    protected array $fillable = [
        'type',
        'external_id',
        'title',
        'url',
        'thumbnail',
        'description',
        'metadata',
        'last_synced_at',
        'status',
    ];

    protected array $casts = [
        'metadata'      => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function userSources(): HasMany
    {
        return $this->hasMany(UserSource::class, 'source_id', 'id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'source_id', 'id');
    }

    public function summaryConfigs()
    {
        return $this->belongsToMany(SummaryConfig::class, 'summary_config_sources', 'source_id', 'config_id');
    }
}
```

- [ ] **Step 5: Create the SourceFactory**

```php
<?php
// database/factories/SourceFactory.php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use Hypervel\Database\Eloquent\Factories\Factory;

class SourceFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement([Source::TYPE_YOUTUBE_CHANNEL, Source::TYPE_YOUTUBE_PLAYLIST]);
        $externalId = $type === Source::TYPE_YOUTUBE_CHANNEL
            ? 'UC' . fake()->regexify('[A-Za-z0-9_-]{22}')
            : 'PL' . fake()->regexify('[A-Za-z0-9_-]{32}');

        return [
            'type'        => $type,
            'external_id' => $externalId,
            'title'       => fake()->sentence(3),
            'url'         => 'https://www.youtube.com/channel/' . $externalId,
            'status'      => Source::STATUS_ACTIVE,
        ];
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/SourceTest.php -v
```

Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_12_100000_create_sources_table.php \
        app/Models/Source.php \
        database/factories/SourceFactory.php \
        tests/Feature/Models/SourceTest.php
git commit -m "feat(schema): add sources table and Source model"
```

---

## Task 2: user_sources Table + UserSource Model

`user_sources` replaces the `rss_id`-side of `userables`. Existing `userables` and `Rss`/`User` relationships are untouched.

**Files:**
- Create: `database/migrations/2026_05_12_100001_create_user_sources_table.php`
- Create: `app/Models/UserSource.php`
- Modify: `app/Models/User.php` — add `sources()` relationship
- Create: `tests/Feature/Models/UserSourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Models/UserSourceTest.php
declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use App\Models\UserSource;
use Hypervel\Foundation\Testing\RefreshDatabase;

class UserSourceTest extends TestCase
{
    use RefreshDatabase;

    public function testUserCanSubscribeToSource(): void
    {
        $user   = User::factory()->create();
        $source = Source::factory()->create();

        $user->sources()->attach($source->id);

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $source->id,
        ]);

        $this->assertCount(1, $user->sources);
    }

    public function testUniqueConstraintPreventsduplicates(): void
    {
        $user   = User::factory()->create();
        $source = Source::factory()->create();

        $user->sources()->attach($source->id);

        $this->expectException(\Throwable::class);
        $user->sources()->attach($source->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/UserSourceTest.php -v
```

Expected: FAIL — `Table "user_sources" doesn't exist` or method not found.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_05_12_100001_create_user_sources_table.php
declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('user_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->foreignUlid('source_id')->index()->comment('來源 ID');
            $this->timestampsWithIndex($table, false, false);

            $table->unique(['user_id', 'source_id']);
            $table->comment('使用者訂閱的來源');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sources');
    }
};
```

- [ ] **Step 4: Create the UserSource model**

```php
<?php
// app/Models/UserSource.php
declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;

class UserSource extends Model
{
    use HasUlids;

    protected ?string $table = 'user_sources';

    protected array $fillable = [
        'user_id',
        'source_id',
    ];

    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'id');
    }
}
```

- [ ] **Step 5: Add `sources()` relationship to User model**

In `app/Models/User.php`, add this method after the existing `rss()` method:

```php
public function sources()
{
    return $this->belongsToMany(
        Source::class,
        'user_sources',
        'user_id',
        'source_id'
    )->withTimestamps();
}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/UserSourceTest.php -v
```

Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_12_100001_create_user_sources_table.php \
        app/Models/UserSource.php \
        app/Models/User.php \
        tests/Feature/Models/UserSourceTest.php
git commit -m "feat(schema): add user_sources table and UserSource model"
```

---

## Task 3: Add source_id and language to media Table

**Files:**
- Create: `database/migrations/2026_05_12_100002_add_source_id_language_to_media_table.php`
- Modify: `app/Models/Media.php` — add `source_id`, `language` to fillable/casts, add `source()` relationship

- [ ] **Step 1: Create the migration**

```php
<?php
// database/migrations/2026_05_12_100002_add_source_id_language_to_media_table.php
declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->foreignUlid('source_id')->nullable()->index()->after('resource_id')->comment('來源 ID');
            $table->string('language')->nullable()->after('duration')->comment('影片主語言');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('source_id');
            $table->dropColumn('language');
        });
    }
};
```

- [ ] **Step 2: Update Media model**

In `app/Models/Media.php`:

Add `'source_id'` and `'language'` to `$fillable`:
```php
protected array $fillable = [
    'type',
    'resource_id',
    'source_id',
    'title',
    'description',
    'duration',
    'language',
    'thumbnail',
    'published_at',
    'status',
    'video_detail',
    'audio_detail',
];
```

Add to `$casts`:
```php
protected array $casts = [
    'duration'     => 'integer',
    'video_detail' => 'array',
    'audio_detail' => 'array',
    'source_id'    => 'string',
    'language'     => 'string',
];
```

Add this relationship method after `users()`:
```php
use Hyperf\Database\Model\Relations\BelongsTo;

public function source(): BelongsTo
{
    return $this->belongsTo(Source::class, 'source_id', 'id');
}
```

- [ ] **Step 3: Run the full test suite to confirm no regression**

```bash
docker compose exec hypervel vendor/bin/phpunit -v
```

Expected: all previously passing tests still pass.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_12_100002_add_source_id_language_to_media_table.php \
        app/Models/Media.php
git commit -m "feat(schema): add source_id and language columns to media table"
```

---

## Task 4: watch_history Table + WatchHistory Model

**Files:**
- Create: `database/migrations/2026_05_12_100003_create_watch_history_table.php`
- Create: `app/Models/WatchHistory.php`
- Create: `database/factories/WatchHistoryFactory.php`
- Modify: `app/Models/User.php` — add `watchHistory()` relationship
- Modify: `app/Models/Media.php` — add `watchHistories()` relationship
- Create: `tests/Feature/Models/WatchHistoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Models/WatchHistoryTest.php
declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\WatchHistory;
use Hypervel\Foundation\Testing\RefreshDatabase;

class WatchHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function testCanRecordWatchHistory(): void
    {
        $user  = User::factory()->create();
        $media = Media::factory()->create();

        $history = WatchHistory::create([
            'user_id'          => $user->id,
            'media_id'         => $media->id,
            'progress_seconds' => 120,
            'completed'        => false,
            'watched_at'       => now(),
        ]);

        $this->assertDatabaseHas('watch_history', [
            'user_id'          => $user->id,
            'media_id'         => $media->id,
            'progress_seconds' => 120,
        ]);

        $this->assertNotNull($history->id);
    }

    public function testUserWatchHistoryRelationship(): void
    {
        $user  = User::factory()->create();
        $media = Media::factory()->create();

        WatchHistory::create([
            'user_id'    => $user->id,
            'media_id'   => $media->id,
            'watched_at' => now(),
        ]);

        $this->assertCount(1, $user->watchHistory);
        $this->assertEquals($media->id, $user->watchHistory->first()->media_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/WatchHistoryTest.php -v
```

Expected: FAIL — `Class "App\Models\WatchHistory" not found` or `Table "watch_history" doesn't exist`.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_05_12_100003_create_watch_history_table.php
declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('watch_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->foreignUlid('media_id')->index()->comment('媒體 ID');
            $table->unsignedInteger('progress_seconds')->default(0)->comment('觀看進度（秒）');
            $table->boolean('completed')->default(false)->index()->comment('是否看完');
            $table->timestamp('watched_at')->index()->comment('觀看時間');
            $this->timestampsWithIndex($table, false, false);

            $table->comment('觀看紀錄');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_history');
    }
};
```

- [ ] **Step 4: Create the WatchHistory model**

```php
<?php
// app/Models/WatchHistory.php
declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class WatchHistory extends Model
{
    use HasUlids;
    use HasFactory;

    protected ?string $table = 'watch_history';

    protected array $fillable = [
        'user_id',
        'media_id',
        'progress_seconds',
        'completed',
        'watched_at',
    ];

    protected array $casts = [
        'progress_seconds' => 'integer',
        'completed'        => 'boolean',
        'watched_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }
}
```

- [ ] **Step 5: Create the WatchHistoryFactory**

```php
<?php
// database/factories/WatchHistoryFactory.php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Media;
use App\Models\WatchHistory;
use Hypervel\Database\Eloquent\Factories\Factory;

class WatchHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'          => User::factory(),
            'media_id'         => Media::factory(),
            'progress_seconds' => fake()->numberBetween(0, 3600),
            'completed'        => fake()->boolean(),
            'watched_at'       => fake()->dateTimeThisMonth(),
        ];
    }
}
```

- [ ] **Step 6: Add `watchHistory()` to User model**

In `app/Models/User.php`, add after the `sources()` method:

```php
public function watchHistory(): HasMany
{
    return $this->hasMany(WatchHistory::class, 'user_id', 'id');
}
```

- [ ] **Step 7: Add `watchHistories()` to Media model**

In `app/Models/Media.php`, add after the `source()` method:

```php
public function watchHistories(): HasMany
{
    return $this->hasMany(WatchHistory::class, 'media_id', 'id');
}
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/WatchHistoryTest.php -v
```

Expected: PASS (2 tests)

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_05_12_100003_create_watch_history_table.php \
        app/Models/WatchHistory.php \
        app/Models/User.php \
        app/Models/Media.php \
        database/factories/WatchHistoryFactory.php \
        tests/Feature/Models/WatchHistoryTest.php
git commit -m "feat(schema): add watch_history table and WatchHistory model"
```

---

## Task 5: chat_sessions + chat_messages Tables + Models

**Files:**
- Create: `database/migrations/2026_05_12_100004_create_chat_sessions_table.php`
- Create: `database/migrations/2026_05_12_100005_create_chat_messages_table.php`
- Create: `app/Models/ChatSession.php`
- Create: `app/Models/ChatMessage.php`
- Create: `database/factories/ChatSessionFactory.php`
- Create: `database/factories/ChatMessageFactory.php`
- Modify: `app/Models/User.php` — add `chatSessions()` relationship
- Modify: `app/Models/Media.php` — add `chatSessions()` relationship
- Create: `tests/Feature/Models/ChatSessionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Models/ChatSessionTest.php
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/ChatSessionTest.php -v
```

Expected: FAIL — `Table "chat_sessions" doesn't exist`.

- [ ] **Step 3: Create the chat_sessions migration**

```php
<?php
// database/migrations/2026_05_12_100004_create_chat_sessions_table.php
declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->foreignUlid('media_id')->index()->comment('媒體 ID');
            $table->string('title')->nullable()->comment('對話標題');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('AI 對話 Session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
```

- [ ] **Step 4: Create the chat_messages migration**

```php
<?php
// database/migrations/2026_05_12_100005_create_chat_messages_table.php
declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->index()->comment('Session ID');
            $table->string('role')->index()->comment('角色: user | ai');
            $table->text('content')->comment('訊息內容');
            $table->timestamp('created_at')->nullable()->index()->comment('創建時間');

            $table->comment('AI 對話訊息');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
```

- [ ] **Step 5: Create the ChatSession model**

```php
<?php
// app/Models/ChatSession.php
declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class ChatSession extends Model
{
    use HasUlids;
    use SoftDeletes;
    use HasFactory;

    protected ?string $table = 'chat_sessions';

    protected array $fillable = [
        'user_id',
        'media_id',
        'title',
    ];

    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id', 'id')->orderBy('created_at');
    }
}
```

- [ ] **Step 6: Create the ChatMessage model**

```php
<?php
// app/Models/ChatMessage.php
declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class ChatMessage extends Model
{
    use HasUlids;
    use HasFactory;

    public const string ROLE_USER = 'user';
    public const string ROLE_AI   = 'ai';

    public static array $roleMaps = [
        self::ROLE_USER => '使用者',
        self::ROLE_AI   => 'AI',
    ];

    protected ?string $table = 'chat_messages';

    public $timestamps = false;

    protected array $fillable = [
        'session_id',
        'role',
        'content',
        'created_at',
    ];

    protected array $casts = [
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'id');
    }
}
```

- [ ] **Step 7: Create ChatSessionFactory**

```php
<?php
// database/factories/ChatSessionFactory.php
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
```

- [ ] **Step 8: Create ChatMessageFactory**

```php
<?php
// database/factories/ChatMessageFactory.php
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
```

- [ ] **Step 9: Add `chatSessions()` to User model**

In `app/Models/User.php`, add after `watchHistory()`:

```php
public function chatSessions(): HasMany
{
    return $this->hasMany(ChatSession::class, 'user_id', 'id');
}
```

- [ ] **Step 10: Add `chatSessions()` to Media model**

In `app/Models/Media.php`, add after `watchHistories()`:

```php
public function chatSessions(): HasMany
{
    return $this->hasMany(ChatSession::class, 'media_id', 'id');
}
```

- [ ] **Step 11: Run tests to verify they pass**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/ChatSessionTest.php -v
```

Expected: PASS (3 tests)

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_05_12_100004_create_chat_sessions_table.php \
        database/migrations/2026_05_12_100005_create_chat_messages_table.php \
        app/Models/ChatSession.php \
        app/Models/ChatMessage.php \
        app/Models/User.php \
        app/Models/Media.php \
        database/factories/ChatSessionFactory.php \
        database/factories/ChatMessageFactory.php \
        tests/Feature/Models/ChatSessionTest.php
git commit -m "feat(schema): add chat_sessions and chat_messages tables with models"
```

---

## Task 6: summary_configs + summary_config_sources Tables + SummaryConfig Model

`summary_configs` replaces `custom_prompts` semantically. The `custom_prompts` table stays untouched.

**Files:**
- Create: `database/migrations/2026_05_12_100006_create_summary_configs_table.php`
- Create: `database/migrations/2026_05_12_100007_create_summary_config_sources_table.php`
- Create: `app/Models/SummaryConfig.php`
- Create: `database/factories/SummaryConfigFactory.php`
- Modify: `app/Models/User.php` — add `summaryConfigs()` relationship
- Create: `tests/Feature/Models/SummaryConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Models/SummaryConfigTest.php
declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use App\Models\SummaryConfig;
use Hypervel\Foundation\Testing\RefreshDatabase;

class SummaryConfigTest extends TestCase
{
    use RefreshDatabase;

    public function testCanCreateSummaryConfig(): void
    {
        $user = User::factory()->create();

        $config = SummaryConfig::create([
            'user_id'     => $user->id,
            'title'       => 'Business Summary',
            'prompt_type' => SummaryConfig::PROMPT_TYPE_BUSINESS,
            'content'     => 'Summarize with a focus on business impact.',
            'ai_model'    => 'claude-sonnet-4-6',
        ]);

        $this->assertDatabaseHas('summary_configs', [
            'user_id'     => $user->id,
            'prompt_type' => SummaryConfig::PROMPT_TYPE_BUSINESS,
        ]);

        $this->assertNotNull($config->id);
    }

    public function testSummaryConfigCanBeLinkedToSources(): void
    {
        $user    = User::factory()->create();
        $source1 = Source::factory()->create();
        $source2 = Source::factory()->create();

        $config = SummaryConfig::create([
            'user_id'     => $user->id,
            'title'       => 'My Config',
            'prompt_type' => SummaryConfig::PROMPT_TYPE_DEFAULT,
        ]);

        $config->sources()->attach([$source1->id, $source2->id]);

        $this->assertDatabaseHas('summary_config_sources', ['config_id' => $config->id, 'source_id' => $source1->id]);
        $this->assertDatabaseHas('summary_config_sources', ['config_id' => $config->id, 'source_id' => $source2->id]);
        $this->assertCount(2, $config->sources);
    }

    public function testUserSummaryConfigsRelationship(): void
    {
        $user = User::factory()->create();
        SummaryConfig::factory()->create(['user_id' => $user->id]);
        SummaryConfig::factory()->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->summaryConfigs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/SummaryConfigTest.php -v
```

Expected: FAIL — `Table "summary_configs" doesn't exist`.

- [ ] **Step 3: Create the summary_configs migration**

```php
<?php
// database/migrations/2026_05_12_100006_create_summary_configs_table.php
declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('summary_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->string('title')->comment('設定名稱');
            $table->string('prompt_type')->default('custom')->index()->comment('提示類型: default|notes|business|tldr|custom');
            $table->text('content')->nullable()->comment('自訂提示內容');
            $table->string('ai_model')->nullable()->comment('AI 模型');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('摘要設定（原 custom_prompts，擴充版）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_configs');
    }
};
```

- [ ] **Step 4: Create the summary_config_sources migration**

```php
<?php
// database/migrations/2026_05_12_100007_create_summary_config_sources_table.php
declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('summary_config_sources', function (Blueprint $table) {
            $table->foreignUlid('config_id')->index()->comment('Summary Config ID');
            $table->foreignUlid('source_id')->index()->comment('來源 ID');

            $table->primary(['config_id', 'source_id']);

            $table->comment('Summary Config 與來源的多對多');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_config_sources');
    }
};
```

- [ ] **Step 5: Create the SummaryConfig model**

```php
<?php
// app/Models/SummaryConfig.php
declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class SummaryConfig extends Model
{
    use HasUlids;
    use SoftDeletes;
    use HasFactory;

    public const string PROMPT_TYPE_DEFAULT  = 'default';
    public const string PROMPT_TYPE_NOTES    = 'notes';
    public const string PROMPT_TYPE_BUSINESS = 'business';
    public const string PROMPT_TYPE_TLDR     = 'tldr';
    public const string PROMPT_TYPE_CUSTOM   = 'custom';

    public static array $promptTypeMaps = [
        self::PROMPT_TYPE_DEFAULT  => '預設',
        self::PROMPT_TYPE_NOTES    => '筆記',
        self::PROMPT_TYPE_BUSINESS => '商業',
        self::PROMPT_TYPE_TLDR     => 'TL;DR',
        self::PROMPT_TYPE_CUSTOM   => '自訂',
    ];

    protected ?string $table = 'summary_configs';

    protected array $fillable = [
        'user_id',
        'title',
        'prompt_type',
        'content',
        'ai_model',
    ];

    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function sources()
    {
        return $this->belongsToMany(
            Source::class,
            'summary_config_sources',
            'config_id',
            'source_id'
        );
    }
}
```

- [ ] **Step 6: Create SummaryConfigFactory**

```php
<?php
// database/factories/SummaryConfigFactory.php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\SummaryConfig;
use Hypervel\Database\Eloquent\Factories\Factory;

class SummaryConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'title'       => fake()->sentence(3),
            'prompt_type' => fake()->randomElement(array_keys(SummaryConfig::$promptTypeMaps)),
            'content'     => fake()->paragraph(),
            'ai_model'    => null,
        ];
    }
}
```

- [ ] **Step 7: Add `summaryConfigs()` to User model**

In `app/Models/User.php`, add after `chatSessions()`:

```php
public function summaryConfigs(): HasMany
{
    return $this->hasMany(SummaryConfig::class, 'user_id', 'id');
}
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/SummaryConfigTest.php -v
```

Expected: PASS (3 tests)

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_05_12_100006_create_summary_configs_table.php \
        database/migrations/2026_05_12_100007_create_summary_config_sources_table.php \
        app/Models/SummaryConfig.php \
        app/Models/User.php \
        database/factories/SummaryConfigFactory.php \
        tests/Feature/Models/SummaryConfigTest.php
git commit -m "feat(schema): add summary_configs and summary_config_sources tables with model"
```

---

## Task 7: Add Columns to summaries Table

**Files:**
- Create: `database/migrations/2026_05_12_100008_add_columns_to_summaries_table.php`
- Modify: `app/Models/Summary.php` — add `config_id`, `ai_model`, `prompt_type` to fillable/casts, add `config()` relationship

- [ ] **Step 1: Create the migration**

```php
<?php
// database/migrations/2026_05_12_100008_add_columns_to_summaries_table.php
declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->foreignUlid('config_id')->nullable()->index()->after('media_id')->comment('Summary Config ID');
            $table->string('ai_model')->nullable()->after('status')->comment('使用的 AI 模型');
            $table->string('prompt_type')->nullable()->after('ai_model')->comment('提示類型');
        });
    }

    public function down(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->dropColumn('config_id');
            $table->dropColumn('ai_model');
            $table->dropColumn('prompt_type');
        });
    }
};
```

- [ ] **Step 2: Update Summary model**

In `app/Models/Summary.php`, update `$fillable`:

```php
protected array $fillable = [
    'media_id',
    'config_id',
    'locale',
    'text',
    'status',
    'ai_model',
    'prompt_type',
];
```

Update `$casts`:

```php
protected array $casts = [
    'media_id'    => 'integer',
    'locale'      => 'string',
    'text'        => 'array',
    'ai_model'    => 'string',
    'prompt_type' => 'string',
];
```

Add this import and method:

```php
// At top of class, add after existing use statements for relations:
use Hyperf\Database\Model\Relations\BelongsTo; // already present

// Add new relationship method after media():
public function config(): BelongsTo
{
    return $this->belongsTo(SummaryConfig::class, 'config_id', 'id');
}
```

- [ ] **Step 3: Run the full test suite to confirm no regression**

```bash
docker compose exec hypervel vendor/bin/phpunit -v
```

Expected: all previously passing tests still pass.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_12_100008_add_columns_to_summaries_table.php \
        app/Models/Summary.php
git commit -m "feat(schema): add config_id, ai_model, prompt_type to summaries table"
```

---

## Task 8: Add Feature Flags to plans Table

**Files:**
- Create: `database/migrations/2026_05_12_100009_add_feature_flags_to_plans_table.php`
- Modify: `app/Models/Plan.php` — add 4 feature flag constants, update fillable/casts

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Models/PlanFeatureFlagsTest.php
declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Plan;
use Hypervel\Foundation\Testing\RefreshDatabase;

class PlanFeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function testPlanHasFeatureFlagColumns(): void
    {
        $plan = Plan::factory()->create([
            'download_enabled'       => true,
            'agent_enabled'          => false,
            'advanced_model_enabled' => true,
            'custom_summary_enabled' => false,
        ]);

        $this->assertDatabaseHas('plans', [
            'id'                     => $plan->id,
            'download_enabled'       => 1,
            'agent_enabled'          => 0,
            'advanced_model_enabled' => 1,
            'custom_summary_enabled' => 0,
        ]);
    }

    public function testFeatureFlagsDefaultToFalse(): void
    {
        $plan = Plan::factory()->create();

        $fresh = Plan::find($plan->id);
        $this->assertFalse($fresh->download_enabled);
        $this->assertFalse($fresh->agent_enabled);
        $this->assertFalse($fresh->advanced_model_enabled);
        $this->assertFalse($fresh->custom_summary_enabled);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/PlanFeatureFlagsTest.php -v
```

Expected: FAIL — `Column "download_enabled" doesn't exist`.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_05_12_100009_add_feature_flags_to_plans_table.php
declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('download_enabled')->default(false)->after('chat_limit')->comment('可下載摘要/字幕');
            $table->boolean('agent_enabled')->default(false)->after('download_enabled')->comment('可使用 Agent 功能');
            $table->boolean('advanced_model_enabled')->default(false)->after('agent_enabled')->comment('可選擇進階 AI 模型');
            $table->boolean('custom_summary_enabled')->default(false)->after('advanced_model_enabled')->comment('可自訂影片總結');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('download_enabled');
            $table->dropColumn('agent_enabled');
            $table->dropColumn('advanced_model_enabled');
            $table->dropColumn('custom_summary_enabled');
        });
    }
};
```

- [ ] **Step 4: Update Plan model**

In `app/Models/Plan.php`, add constants after existing status constants:

```php
public const bool DOWNLOAD_ENABLED_DEFAULT       = false;
public const bool AGENT_ENABLED_DEFAULT          = false;
public const bool ADVANCED_MODEL_ENABLED_DEFAULT = false;
public const bool CUSTOM_SUMMARY_ENABLED_DEFAULT = false;
```

Update `$fillable`:

```php
protected array $fillable = [
    'title',
    'description',
    'channel_limit',
    'video_limit',
    'chat_limit',
    'download_enabled',
    'agent_enabled',
    'advanced_model_enabled',
    'custom_summary_enabled',
    'sort',
    'status',
];
```

Update `$casts`:

```php
protected array $casts = [
    'sort'                   => 'integer',
    'download_enabled'       => 'boolean',
    'agent_enabled'          => 'boolean',
    'advanced_model_enabled' => 'boolean',
    'custom_summary_enabled' => 'boolean',
];
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/PlanFeatureFlagsTest.php -v
```

Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_12_100009_add_feature_flags_to_plans_table.php \
        app/Models/Plan.php \
        tests/Feature/Models/PlanFeatureFlagsTest.php
git commit -m "feat(schema): add feature flag columns to plans table"
```

---

## Task 9: Add user_id to feedbacks Table

**Files:**
- Create: `database/migrations/2026_05_12_100010_add_user_id_to_feedbacks_table.php`
- Modify: `app/Models/Feedback.php` — add `user_id` to fillable, add `user()` relationship
- Create: `tests/Feature/Models/FeedbackUserTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Models/FeedbackUserTest.php
declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Feedback;
use Hypervel\Foundation\Testing\RefreshDatabase;

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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/FeedbackUserTest.php -v
```

Expected: FAIL — `Column "user_id" doesn't exist`.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_05_12_100010_add_user_id_to_feedbacks_table.php
declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->foreignUlid('user_id')->nullable()->index()->after('id')->comment('使用者 ID');
        });
    }

    public function down(): void
    {
        Schema::table('feedbacks', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};
```

- [ ] **Step 4: Update Feedback model**

In `app/Models/Feedback.php`:

Update `$fillable`:

```php
protected array $fillable = [
    'user_id',
    'content',
    'status',
];
```

Add this import (already present via base) and method after `images()`:

```php
use Hyperf\Database\Model\Relations\BelongsTo;

public function user(): BelongsTo
{
    return $this->belongsTo(User::class, 'user_id', 'id');
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/Models/FeedbackUserTest.php -v
```

Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_12_100010_add_user_id_to_feedbacks_table.php \
        app/Models/Feedback.php \
        tests/Feature/Models/FeedbackUserTest.php
git commit -m "feat(schema): add user_id to feedbacks table"
```

---

## Task 10: Add version to settings Table + Fix CustomPrompt Bug

**Files:**
- Create: `database/migrations/2026_05_12_100011_add_version_to_settings_table.php`
- Modify: `app/Models/Setting.php` — add `version` to fillable/casts
- Modify: `app/Models/CustomPrompt.php` — fix wrong table name (`custom_settings` → `custom_prompts`)

- [ ] **Step 1: Create the migration**

```php
<?php
// database/migrations/2026_05_12_100011_add_version_to_settings_table.php
declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('data')->comment('Schema 版本號，用於未來 migration');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
```

- [ ] **Step 2: Update Setting model**

In `app/Models/Setting.php`:

Update `$fillable`:

```php
protected array $fillable = [
    'user_id',
    'data',
    'version',
];
```

Update `$casts`:

```php
protected array $casts = [
    'data'    => 'array',
    'version' => 'integer',
];
```

- [ ] **Step 3: Fix CustomPrompt model table name**

In `app/Models/CustomPrompt.php`, change:

```php
protected ?string $table = 'custom_settings';
```

to:

```php
protected ?string $table = 'custom_prompts';
```

- [ ] **Step 4: Run the full test suite to confirm no regressions**

```bash
docker compose exec hypervel vendor/bin/phpunit -v
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_12_100011_add_version_to_settings_table.php \
        app/Models/Setting.php \
        app/Models/CustomPrompt.php
git commit -m "feat(schema): add version to settings table; fix CustomPrompt table name bug"
```

---

## Task 11: Final Integration Verification

- [ ] **Step 1: Run fresh migration on test DB**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/RefreshDatabaseTest.php -v
```

Expected: PASS.

- [ ] **Step 2: Run the complete test suite**

```bash
docker compose exec hypervel vendor/bin/phpunit -v
```

Expected: all tests pass, no failures.

- [ ] **Step 3: Run static analysis**

```bash
docker compose exec hypervel composer analyse
```

Expected: no errors introduced by the new models.

- [ ] **Step 4: Run code style fixer**

```bash
docker compose exec hypervel composer cs-diff
```

Fix any reported style issues:

```bash
docker compose exec hypervel composer cs-fix app/Models/Source.php \
    app/Models/UserSource.php \
    app/Models/WatchHistory.php \
    app/Models/ChatSession.php \
    app/Models/ChatMessage.php \
    app/Models/SummaryConfig.php \
    app/Models/Media.php \
    app/Models/User.php \
    app/Models/Summary.php \
    app/Models/Plan.php \
    app/Models/Feedback.php \
    app/Models/Setting.php \
    app/Models/CustomPrompt.php
```

- [ ] **Step 5: Commit any style fixes**

```bash
git add -p
git commit -m "style: apply cs-fixer to new and modified models"
```
