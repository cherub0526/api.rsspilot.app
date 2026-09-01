---
paths:
  - "tests/**"
---

# 測試規則（PHPUnit / Hypervel Feature & Unit Tests）

## 測試類型選擇

| 情境 | 測試類型 | 目錄 |
|------|---------|------|
| HTTP endpoint 行為 | Feature Test | `tests/Feature/API/V1/` |
| 單一 class/method 邏輯 | Unit Test | `tests/Unit/{Layer}/` |
| 外部服務整合 | Feature Test + Mock | `tests/Feature/` |

**預設選擇 Feature Test**，直接測試 HTTP 行為比測試內部邏輯更有價值。
Feature Test 的目錄鏡射 Controller 位置：`API/V1/SourcesController` → `tests/Feature/API/V1/SourcesControllerTest.php`。

## 基礎結構

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Source;
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

        $user = $this->fakeLogin();

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
```

## 與 Laravel 不同的地方

| 項目 | 本專案 |
|------|--------|
| 基底類別 | `Tests\TestCase`（已 `use RunTestsInCoroutine`） |
| RefreshDatabase | `Hypervel\Foundation\Testing\RefreshDatabase` |
| 測試 DB | sqlite（`DB_CONNECTION=sqlite_testing`，見 `phpunit.xml.dist`） |
| 登入 | `$this->fakeLogin()`（建 User + `actingAs`，回傳該 User） |
| 執行 | `composer test`（**沒有** `php artisan test`、沒有 `--parallel`） |

> **不要用 `vendor/bin/co-phpunit`。** 它把整個 PHPUnit 包進單一協程，
> `RunTestsInCoroutine` 的「每測試一協程」包裝會因此完全不執行，502 個測試
> 共用同一份 `Context`——`actingAs()` 的登入狀態跨測試外洩，所有斷言 401 的
> 測試會拿到 2xx（實測 38 個假失敗）。也不要為了讓它變綠去改 `tearDown()`，
> 那會傷到真正在用的 runner。完整因果見
> `docs/lore/framework/pitfalls.md`〈`co-phpunit` 會關掉每測試一協程的隔離〉。

## 命名規範

- 方法名稱用 `test` 前綴 + **PascalCase**：`testIndex()`、`testStoreChannel()`
  （**不是** `test_snake_case`——本專案全數採 PascalCase）
- 名稱描述**行為或情境**：`testStoreRejectsInvalidUrl()`
- 負面情境也要測：未登入回 401、超出額度回 400
- 類別必須帶 `@internal` 與 `@coversNothing` docblock

## 必用 Trait

```php
use RefreshDatabase;   // 測試間資料庫隔離，Feature Test 一律加
```

## 認證

```php
// 建立使用者並登入，回傳該 User
$user = $this->fakeLogin();

// 指定使用者
$user = User::factory()->create();
$this->actingAs($user);

// 驗證未登入被擋
$this->json('GET', $uri)->assertStatus(401);
```

## 路由一律用 `route()` 取得

**不要在測試裡寫死 URL 字串。** 用 `route()` 取，路由改路徑時測試會跟著動、
route name 打錯時測試會直接失敗——這是 route name 規範的實際驗收點。

```php
// ✅
$uri = route('api.v1.media.summaries.index', ['mediaId' => $media->id]);

// ❌
$uri = '/v1/media/' . $media->id . '/summaries';
```

## Factory 使用

```php
$user     = User::factory()->create();
$source   = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL]);
$sources  = Source::factory()->count(5)->create();
```

Factory 放 `database/factories/`。建立測試資料時**引用 Model 常數**，不寫死字串。

## Assert 慣例

```php
// 本專案以 assertStatus() 為主
$response->assertStatus(200);
$response->assertStatus(401);

// JSON 結構驗證
$response->assertJsonCount(2, 'data');
$response->assertJsonPath('data.0.id', $source->id);
$response->assertJsonFragment(['type' => 'channel']);
```

> 列表回應包在 `data` 底下、單筆回應不包——原因見 `resources.md` 的 `$wrap` 一節。

## 外部服務一律 mock

測試**不得**對外發請求。`phpunit.xml.dist` 已把 `OPENROUTER_API_KEY`、`STRIPE_API_KEY`
換成無效字串當作最後防線，但那是安全網不是設計：

```php
use Hypervel\Support\Facades\Http;

Http::fake([...]);

$this->mock(YoutubeService::class, function (MockInterface $mock) {
    $mock->shouldReceive('fetch')->andReturn([...]);
});
```

Observer 會呼叫外部 API 的 Model（`Plan`、`Price`、`User`）在測試中建立時，
記得包 `withoutEvents()`，否則 observer 會真的送出請求。

## 執行測試

```bash
docker compose exec hypervel composer test                              # 全部
docker compose exec hypervel vendor/bin/phpunit tests/Feature/API/V1     # 單一目錄
docker compose exec hypervel vendor/bin/phpunit --filter=testIndex       # 單一測試
```

`vendor/bin/co-phpunit` 不在這個清單裡是刻意的——理由見上方的警告。
