# Avatar Upload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 新增 `POST /v1/users/avatar` 端點，讓已登入使用者上傳個人頭像至 S3，並透過 CDN URL 回傳完整 `UserResource`。

**Architecture:** 獨立 `AvatarController`（子 controller 模式）處理上傳邏輯；每次上傳在 `user_avatars` 表新增歷史紀錄，同時更新 `users.avatar` 為最新 S3 相對路徑；`UserResource` 動態組合 CDN URL。

**Tech Stack:** Hypervel (Laravel-compatible), S3 (`Storage::disk('s3')`), UUID 唯一檔名, ULID PK, PHPUnit feature tests, `Storage::fake('s3')` for test isolation.

---

## File Map

| 動作 | 路徑 |
|------|------|
| **Create** | `database/migrations/2026_05_27_100000_create_user_avatars_table.php` |
| **Create** | `app/Models/UserAvatar.php` |
| **Create** | `app/Validators/AvatarValidator.php` |
| **Create** | `app/Http/Controllers/API/V1/Users/AvatarController.php` |
| **Create** | `tests/Feature/API/V1/Users/AvatarControllerTest.php` |
| **Create** | `tests/Unit/Resources/UserResourceAvatarTest.php` |
| **Modify** | `app/Models/User.php` — add `avatar` to `$fillable`, add `avatars()` |
| **Modify** | `app/Http/Resources/UserResource.php` — add `avatar` CDN URL |
| **Modify** | `app/OpenApi/Schemas/UserResource.php` — add `avatar` property |
| **Modify** | `routes/v1.php` — add `POST /users/avatar` route |
| **Modify** | `config/app.php` — add `cdn_url` |
| **Modify** | `.env.example` — add `CDN_URL` |

---

## Task 1: Database Migration + UserAvatar Model

**Files:**
- Create: `database/migrations/2026_05_27_100000_create_user_avatars_table.php`
- Create: `app/Models/UserAvatar.php`

- [ ] **Step 1: 建立 migration**

建立 `database/migrations/2026_05_27_100000_create_user_avatars_table.php`：

```php
<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('user_avatars', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->string('filename')->comment('客戶端原始檔名，e.g. my-photo.jpg');
            $table->string('path')->comment('S3 相對路徑，e.g. avatars/{userId}/{uuid}.jpg');
            $this->timestampsWithIndex($table, false, false);

            $table->comment('使用者頭像上傳紀錄');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_avatars');
    }
};
```

- [ ] **Step 2: 執行 migration**

```bash
docker compose exec hypervel php artisan migrate
```

預期輸出包含：`Migrating: 2026_05_27_100000_create_user_avatars_table`

- [ ] **Step 3: 建立 UserAvatar model**

建立 `app/Models/UserAvatar.php`：

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

class UserAvatar extends Model
{
    use HasUlids;

    protected ?string $table = 'user_avatars';

    protected array $fillable = [
        'user_id',
        'filename',
        'path',
    ];

    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_27_100000_create_user_avatars_table.php app/Models/UserAvatar.php
git commit -m "feat(avatar): 新增 user_avatars 資料表與 UserAvatar model"
```

---

## Task 2: User Model 更新

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: 在 `User::$fillable` 加入 `avatar`，並新增 `avatars()` 關聯**

開啟 `app/Models/User.php`，將：

```php
    protected array $fillable = [
        'account',
        'name',
        'email',
        'email_verified_at',
        'password',
        'social_type',
    ];
```

改為：

```php
    protected array $fillable = [
        'account',
        'name',
        'email',
        'email_verified_at',
        'password',
        'social_type',
        'avatar',
    ];
```

並在 `setting()` 方法之後加入：

```php
    public function avatars(): HasMany
    {
        return $this->hasMany(UserAvatar::class, 'user_id', 'id');
    }
```

確認 `use` 區塊已有 `Hypervel\Database\Eloquent\Relations\HasMany`（原本已存在）。

- [ ] **Step 2: Commit**

```bash
git add app/Models/User.php
git commit -m "feat(avatar): 將 avatar 加入 User fillable 並新增 avatars 關聯"
```

---

## Task 3: Config + .env.example 更新

**Files:**
- Modify: `config/app.php`
- Modify: `.env.example`

- [ ] **Step 1: 在 `config/app.php` 加入 `cdn_url`**

找到 `'url' => env('APP_URL', 'http://localhost'),` 這行，在其後新增：

```php
    'cdn_url' => env('CDN_URL', ''),
```

- [ ] **Step 2: 在 `.env.example` 加入 `CDN_URL`**

在 `AWS_URL=` 那一行後面加入：

```
CDN_URL=
```

- [ ] **Step 3: Commit**

```bash
git add config/app.php .env.example
git commit -m "chore: 新增 CDN_URL 環境變數設定"
```

---

## Task 4: UserResource avatar 欄位 (TDD)

**Files:**
- Create: `tests/Unit/Resources/UserResourceAvatarTest.php`
- Modify: `app/Http/Resources/UserResource.php`
- Modify: `app/OpenApi/Schemas/UserResource.php`

- [ ] **Step 1: 撰寫失敗的 unit test**

建立 `tests/Unit/Resources/UserResourceAvatarTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use Tests\TestCase;
use App\Models\User;
use App\Http\Resources\UserResource;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class UserResourceAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function testAvatarReturnsCdnUrlWhenSet(): void
    {
        config(['app.cdn_url' => 'https://cdn.example.com']);

        /** @var User $user */
        $user = User::factory()->make([
            'avatar' => 'avatars/01JXXXXX/550e8400-uuid.jpg',
        ]);

        $resource = new UserResource($user);
        $array = $resource->toArray();

        $this->assertArrayHasKey('avatar', $array);
        $this->assertEquals(
            'https://cdn.example.com/avatars/01JXXXXX/550e8400-uuid.jpg',
            $array['avatar']
        );
    }

    public function testAvatarReturnsNullWhenNotSet(): void
    {
        config(['app.cdn_url' => 'https://cdn.example.com']);

        /** @var User $user */
        $user = User::factory()->make(['avatar' => null]);

        $resource = new UserResource($user);
        $array = $resource->toArray();

        $this->assertArrayHasKey('avatar', $array);
        $this->assertNull($array['avatar']);
    }

    public function testAvatarCdnUrlTrimsTrailingSlash(): void
    {
        config(['app.cdn_url' => 'https://cdn.example.com/']);

        /** @var User $user */
        $user = User::factory()->make([
            'avatar' => 'avatars/01JXXXXX/uuid.jpg',
        ]);

        $resource = new UserResource($user);
        $array = $resource->toArray();

        $this->assertEquals(
            'https://cdn.example.com/avatars/01JXXXXX/uuid.jpg',
            $array['avatar']
        );
    }
}
```

- [ ] **Step 2: 執行 test，確認失敗**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Unit/Resources/UserResourceAvatarTest.php --testdox
```

預期：FAIL，因為 `UserResource` 尚無 `avatar` 欄位。

- [ ] **Step 3: 更新 UserResource**

開啟 `app/Http/Resources/UserResource.php`，在 `toArray()` 的 return array 中加入 `avatar`：

```php
    public function toArray(): array
    {
        return [
            'id'      => strval($this->resource->id),
            'name'    => strval($this->resource->name),
            'email'   => strval($this->resource->email),
            'account' => strval($this->resource->account),
            'avatar'  => $this->resource->avatar
                ? rtrim(config('app.cdn_url'), '/') . '/' . $this->resource->avatar
                : null,
            'setting' => new SettingResource($this->whenLoaded('setting')),
        ];
    }
```

- [ ] **Step 4: 更新 OpenAPI Schema**

開啟 `app/OpenApi/Schemas/UserResource.php`，在 `setting` property 之前加入 `avatar`：

```php
#[OAT\Schema(
    schema: 'UserResource',
    properties: [
        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
        new OAT\Property(property: 'name', type: 'string', example: 'John Doe'),
        new OAT\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
        new OAT\Property(property: 'account', type: 'string', example: 'johndoe'),
        new OAT\Property(
            property: 'avatar',
            type: 'string',
            nullable: true,
            example: 'https://cdn.example.com/avatars/01JCXYZ.../uuid.jpg'
        ),
        new OAT\Property(property: 'setting', type: 'object'),
    ],
    type: 'object'
)]
class UserResource
{
}
```

- [ ] **Step 5: 執行 test，確認通過**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Unit/Resources/UserResourceAvatarTest.php --testdox
```

預期：3 tests PASS。

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/Resources/UserResourceAvatarTest.php app/Http/Resources/UserResource.php app/OpenApi/Schemas/UserResource.php
git commit -m "feat(avatar): UserResource 新增 avatar CDN URL 欄位"
```

---

## Task 5: AvatarValidator (TDD)

**Files:**
- Create: `app/Validators/AvatarValidator.php`

- [ ] **Step 1: 撰寫失敗的 validator unit test**

在現有的 `tests/Unit/` 目錄下建立 `tests/Unit/Validators/AvatarValidatorTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use Tests\TestCase;
use App\Validators\AvatarValidator;

/**
 * @internal
 * @coversNothing
 */
class AvatarValidatorTest extends TestCase
{
    public function testStoreRulesRequiresFile(): void
    {
        $v = new AvatarValidator([]);
        $v->setStoreRules();

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('file', $v->errors()->toArray());
    }

    public function testStoreRulesHasExpectedRules(): void
    {
        $v = new AvatarValidator([]);
        $v->setStoreRules();

        $rules = $v->getRules();

        $this->assertArrayHasKey('file', $rules);
        $this->assertStringContainsString('required', $rules['file']);
        $this->assertStringContainsString('image', $rules['file']);
        $this->assertStringContainsString('max:2048', $rules['file']);
    }
}
```

- [ ] **Step 2: 執行 test，確認失敗**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Unit/Validators/AvatarValidatorTest.php --testdox
```

預期：FAIL，`AvatarValidator` class 不存在。

- [ ] **Step 3: 實作 AvatarValidator**

建立 `app/Validators/AvatarValidator.php`：

```php
<?php

declare(strict_types=1);

namespace App\Validators;

class AvatarValidator extends BaseValidator
{
    public function __construct($params)
    {
        parent::__construct($params);
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'file' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:2048',
        ];

        return $this;
    }
}
```

- [ ] **Step 4: 執行 test，確認通過**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Unit/Validators/AvatarValidatorTest.php --testdox
```

預期：2 tests PASS。

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Validators/AvatarValidatorTest.php app/Validators/AvatarValidator.php
git commit -m "feat(avatar): 新增 AvatarValidator"
```

---

## Task 6: AvatarController + Route (TDD)

**Files:**
- Create: `tests/Feature/API/V1/Users/AvatarControllerTest.php`
- Create: `app/Http/Controllers/API/V1/Users/AvatarController.php`
- Modify: `routes/v1.php`

- [ ] **Step 1: 撰寫所有失敗的 feature tests**

建立 `tests/Feature/API/V1/Users/AvatarControllerTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Users;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserAvatar;
use Illuminate\Http\UploadedFile;
use Hypervel\Support\Facades\Storage;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class AvatarControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testUnauthenticatedUserCannotUploadAvatar(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');

        $this->json('POST', $uri)->assertStatus(401);
    }

    public function testUploadAvatarRequiresFile(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');
        $this->fakeLogin();

        $this->json('POST', $uri, [])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testUploadAvatarValidatesFileType(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');
        $this->fakeLogin();

        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        $this->json('POST', $uri, ['file' => $file])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testUploadAvatarValidatesFileSize(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');
        $this->fakeLogin();

        // 建立超過 2048 KB 的假圖片
        $file = UploadedFile::fake()->image('avatar.jpg')->size(3000);

        $this->json('POST', $uri, ['file' => $file])
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['file']]);
    }

    public function testAuthenticatedUserCanUploadAvatar(): void
    {
        Storage::fake('s3');

        config(['app.cdn_url' => 'https://cdn.example.com']);

        $uri = route('api.v1.users.avatar.store');

        /** @var User $user */
        $user = $this->fakeLogin();

        $file = UploadedFile::fake()->image('my-photo.jpg', 200, 200)->size(500);

        $response = $this->json('POST', $uri, ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id', 'name', 'email', 'account', 'avatar',
            ]);

        // avatar 應為 CDN URL
        $avatarUrl = $response->json('avatar');
        $this->assertStringStartsWith('https://cdn.example.com/avatars/', $avatarUrl);
        $this->assertStringEndsWith('.jpg', $avatarUrl);
    }

    public function testUploadSavesUserAvatarRecord(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');

        /** @var User $user */
        $user = $this->fakeLogin();

        $file = UploadedFile::fake()->image('my-photo.jpg', 200, 200)->size(500);

        $this->json('POST', $uri, ['file' => $file])
            ->assertStatus(200);

        // user_avatars 應有一筆紀錄
        $this->assertDatabaseCount('user_avatars', 1);

        $avatar = UserAvatar::first();
        $this->assertEquals($user->id, $avatar->user_id);
        $this->assertEquals('my-photo.jpg', $avatar->filename);
        $this->assertStringStartsWith("avatars/{$user->id}/", $avatar->path);
        $this->assertStringEndsWith('.jpg', $avatar->path);
    }

    public function testReuploadingCreatesNewAvatarRecord(): void
    {
        Storage::fake('s3');

        $uri = route('api.v1.users.avatar.store');

        /** @var User $user */
        $user = $this->fakeLogin();

        // 第一次上傳
        $file1 = UploadedFile::fake()->image('first.jpg', 100, 100)->size(200);
        $this->json('POST', $uri, ['file' => $file1])->assertStatus(200);

        $firstPath = $user->fresh()->avatar;

        // 第二次上傳
        $file2 = UploadedFile::fake()->image('second.png', 150, 150)->size(300);
        $this->json('POST', $uri, ['file' => $file2])->assertStatus(200);

        $secondPath = $user->fresh()->avatar;

        // user_avatars 應有兩筆
        $this->assertDatabaseCount('user_avatars', 2);

        // users.avatar 應更新為新路徑
        $this->assertNotEquals($firstPath, $secondPath);
        $this->assertStringEndsWith('.png', $secondPath);

        // 確認第一筆歷史紀錄仍存在
        $this->assertDatabaseHas('user_avatars', ['path' => $firstPath]);
        $this->assertDatabaseHas('user_avatars', ['path' => $secondPath]);
    }
}
```

- [ ] **Step 2: 執行 tests，確認全部失敗**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/API/V1/Users/AvatarControllerTest.php --testdox
```

預期：所有 7 個 tests FAIL（route 不存在）。

- [ ] **Step 3: 在 routes/v1.php 加入路由**

在 `routes/v1.php` 加入以下 import：

```php
use App\Http\Controllers\API\V1\Users\AvatarController;
```

在 `/users` route group 中（現有路由之後）加入：

```php
    Route::post('/avatar', [
        'as'         => 'avatar.store',
        'uses'       => AvatarController::class . '@store',
        'middleware' => ['auth'],
    ]);
```

完整 users group 應如下：

```php
Route::group('/users', function () {
    Route::get(
        '/',
        [
            'as'         => 'index',
            'uses'       => UsersController::class . '@index',
            'middleware' => ['auth'],
        ]
    );

    Route::put('/', [
        'as'         => 'update',
        'uses'       => UsersController::class . '@update',
        'middleware' => ['auth'],
    ]);

    Route::post('/avatar', [
        'as'         => 'avatar.store',
        'uses'       => AvatarController::class . '@store',
        'middleware' => ['auth'],
    ]);
}, ['as' => 'users']);
```

- [ ] **Step 4: 實作 AvatarController**

建立 `app/Http/Controllers/API/V1/Users/AvatarController.php`：

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Users;

use App\Models\UserAvatar;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Validators\AvatarValidator;
use App\Http\Resources\UserResource;
use Hypervel\Support\Facades\Storage;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\UserResource as UserSchema;
use Hypervel\Support\Str;

class AvatarController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/users/avatar',
        operationId: 'api.v1.users.avatar.store',
        summary: 'Upload user avatar',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OAT\Schema(
                    required: ['file'],
                    properties: [
                        new OAT\Property(
                            property: 'file',
                            description: 'Avatar image (jpeg, png, jpg, webp; max 2 MB)',
                            type: 'string',
                            format: 'binary'
                        ),
                    ]
                )
            )
        ),
        tags: ['Users'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Updated user profile with avatar URL',
                content: new OAT\JsonContent(ref: UserSchema::class)
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function store(Request $request): UserResource
    {
        $v = new AvatarValidator($request->only(['file']));
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = $request->user();
        $file = $request->file('file');

        $originalFilename = $file->getClientFilename();
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $uuid = (string) Str::uuid();
        $path = "avatars/{$user->id}/{$uuid}.{$ext}";

        Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()));

        UserAvatar::create([
            'user_id'  => $user->id,
            'filename' => $originalFilename,
            'path'     => $path,
        ]);

        $user->update(['avatar' => $path]);

        return new UserResource($user->load(['setting']));
    }
}
```

- [ ] **Step 5: 執行 tests，確認全部通過**

```bash
docker compose exec hypervel vendor/bin/phpunit tests/Feature/API/V1/Users/AvatarControllerTest.php --testdox
```

預期：7 tests PASS。

若測試中 `UploadedFile::fake()` 的 `getClientFilename()` 回傳不如預期（Hypervel PSR-7 wrapper 差異），請改用：

```php
// 在 Controller 中改用 getClientOriginalName() fallback
$originalFilename = method_exists($file, 'getClientOriginalName')
    ? $file->getClientOriginalName()
    : $file->getClientFilename();
```

- [ ] **Step 6: 執行全部測試，確認無 regression**

```bash
docker compose exec hypervel vendor/bin/phpunit --testdox
```

預期：全部 PASS。

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/API/V1/Users/AvatarControllerTest.php \
        app/Http/Controllers/API/V1/Users/AvatarController.php \
        routes/v1.php
git commit -m "feat(avatar): 實作 POST /v1/users/avatar 端點與 feature tests"
```

---

## 驗收標準

全部完成後確認：

```bash
# 所有測試通過
docker compose exec hypervel vendor/bin/phpunit --testdox

# Code style 無錯誤
docker compose exec hypervel composer cs-diff

# 靜態分析無警告
docker compose exec hypervel composer analyse
```
