---
paths:
  - "routes/**"
---

# Routes 命名規則

> **本專案的 Router 不是 Laravel 的。** Hypervel 的 `Route` facade 只有
> `group()` / `get()` / `post()` / `put()` / `patch()` / `delete()` / `any()` / `model()` / `bind()`，
> **沒有** `->name()` / `->prefix()` / `->middleware()` 鏈式語法、**沒有** `apiResource()`、
> **沒有** `Route::pattern()`。所有設定都寫在陣列選項裡。

## 語法形狀

```php
Route::group('/{prefix}', function () {
    Route::get('/{path}', [
        'as'         => '{action}',
        'uses'       => SomeController::class . '@{method}',
        'middleware' => ['auth'],
    ]);
}, ['as' => '{segment}', 'middleware' => ['auth']]);
```

- `'as'` 由外而內以 `.` 自動串接
- `'uses'` 是 `Controller::class . '@method'` 字串，**不是** `[Controller::class, 'method']` 陣列
- 路由參數的約束**寫在路徑裡**：`/{sourceId:[0-7][0-9a-hjkmnp-tv-z]{25}}`

## 命名格式

每條路由都必須能被 `route('api.v1.resource.action')` 找到。route name 由 URL 路徑直接推導：

```
api.{version}.{path-segments}.{action}
```

- `{path-segments}`：URL 中**版本後的所有靜態路徑段**，依序以 `.` 串接。動態參數段（`{mediaId}`、`{sourceId}`）**不計入** name。
- `{action}`：依 HTTP method 對應的 RESTful 動作（見下表），固定為 name 的**最後一段**。

範例：`POST /v1/auth/login` → `api.v1.auth.login.store`

| 段落 | 設定位置 | 範例 |
|------|---------|------|
| `api.{version}.` | `app/Providers/RouteServiceProvider.php` 的 `['as' => 'api.v1']` | `api.v1.` |
| `{path-segments}.` | 各層 `Route::group()` 第三參數的 `['as' => 'auth']` | `auth.` `media.` `media.chat.` |
| `{action}` | 個別路由的 `'as' => 'store'` | `store` `index` `show` |

## RESTful Action 對應

| HTTP | 路徑 | action |
|------|------|--------|
| GET | `/resource` | `index` |
| GET | `/resource/create` | `create` |
| POST | `/resource` | `store` |
| GET | `/resource/{id}` | `show` |
| GET | `/resource/{id}/edit` | `edit` |
| PUT/PATCH | `/resource/{id}` | `update` |
| DELETE | `/resource/{id}` | `destroy` |

> **嚴格限制**：name 的**最後一段（action）只能**是上表 7 個之一（`index`、`create`、`store`、`show`、`edit`、`update`、`destroy`），不得出現任何其他自訂動詞。

### 路徑段可為語意動作，action 仍為 RESTful

URL 的**靜態路徑段**可自由命名以表達語意——包含 `login`、`logout`、`register`、`usage`
這類名詞或動作詞——它們被視為**資源／命名段**，原樣進入 name；HTTP method 決定的
RESTful action 才是最後一段。

| 需求 | URL | HTTP | route name |
|------|-----|------|-----------|
| 註冊 | `/v1/auth/register` | POST | `api.v1.auth.register.store` |
| 登出 | `/v1/auth/logout` | POST | `api.v1.auth.logout.store` |
| 換發 token | `/v1/auth/refresh` | POST | `api.v1.auth.refresh.store` |
| 用量查詢 | `/v1/subscriptions/usage` | GET | `api.v1.subscriptions.usage.index` |

> 路徑段是「名稱」，action 是「動作」。`auth/register` 的 `register` 是路徑段、
> `store` 才是 action——兩者分屬不同層級，互不衝突。

## Controller 與方法對應

route name、**Controller 檔案位置、Controller 方法**三者一律由 URL 路徑 + HTTP method 推導，彼此一致：

- **最後一個靜態路徑段** → Controller 類別：StudlyCase + `Controller`（`register` → `RegisterController`、`sources` → `SourcesController`）
- **前置靜態路徑段** → 子目錄／子命名空間（StudlyCase）：`auth` → `Auth/`、`media/{id}/chat` → `Media/Chat/`
- **action（RESTful 動作）** → Controller 方法：`store` → `store()`
- 動態參數段（`{mediaId}`、`{sourceId}`）**不影響** Controller 對應

命名空間起點固定為 `App\Http\Controllers\API\V1\`（對應 `app/Http/Controllers/API/V1/`）。

以 `POST /v1/auth/register` 為例：

| 項目 | 來源 | 結果 |
|------|------|------|
| route name | path + action | `api.v1.auth.register.store` |
| Controller | 末段 → 類別、前置段 → 目錄 | `App\Http\Controllers\API\V1\Auth\RegisterController` |
| 檔案 | 同上 | `app/Http/Controllers/API/V1/Auth/RegisterController.php` |
| 方法 | action | `store()` |

> **單一動作資源**（`register`、`logout`、`usage`）各自一個 Controller，只含對應的 RESTful 方法。
> **多動作資源**（`sources`、`media`）共用一個 `{Studly}Controller`，方法為 `index`/`store`/`show`/`update`/`destroy`。

### 縮寫例外

已成慣例的全大寫縮寫保留原樣，不硬套 StudlyCase：`rss` → `RSSController`（不是 `RssController`）。
新增縮寫類資源時沿用專案既有寫法，不要為了規則一致而改名既有檔案。

## Middleware 寫在 routes

**與 Laravel 專案常見做法相反**：本專案的 middleware 一律宣告在 routes 檔，
不在 Controller 內設定（Hypervel 沒有 Laravel 11 的 `HasMiddleware` interface）。

- 整個群組共用 → 寫在 `Route::group()` 第三參數
- 只有單條需要 → 寫在該條路由的 `'middleware'`
- 公開端點（webhook、`plans.index`）**不加** `auth`

```php
// ✅ 群組共用
Route::group('/users', function () {
    Route::get('/', ['as' => 'index', 'uses' => UsersController::class . '@index']);
}, ['as' => 'users', 'middleware' => ['auth']]);

// ✅ 個別加碼（額外的 throttle）
Route::get('/sessions/{sessionId}/follow-ups', [
    'as'         => 'sessions.follow-ups.show',
    'uses'       => FollowUpsController::class . '@show',
    'middleware' => ['auth', 'throttle:10,1'],
]);
```

## 規則

✅ **必須**

- 每條路由都有 `'as'`，使最終 name 與 URL 路徑逐字對應
- name 的**最後一段**只能是 7 個 RESTful 標準之一
- 重複出現的路徑段抽成 `Route::group()`，不重複裸寫前綴
- 資源／路徑段名稱用複數或語意化 kebab-case：`sources`、`checkout-session`、`follow-ups`
- 動態參數段**不進入** name
- Controller 檔案位置與方法須對應 URL（見「Controller 與方法對應」）
- ULID 參數帶上約束：`{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}`

❌ **禁止**

- 缺少 `'as'` 的匿名路由
- name 最後一段為自訂動詞（`login`、`refresh`、`checkoutSession`、`usage`…）——動作語意改放**路徑段**
- name 與 URL 路徑不一致（路徑 `auth/register` 卻命名成 `auth.signup`）
- 在個別路由的 `'as'` 重複寫版本前綴（`'as' => 'api.v1.auth.register.store'`）
- 單一 Controller 承載不相干路徑段（`auth/register`、`auth/logout` 全塞進 `AuthController`）
- camelCase 的路徑段或 action（`checkoutSession`）——路徑段用 kebab-case，action 用 RESTful 動詞

## 巢狀資源與參數群組

同一個路徑段在多條路由重複出現時，**一律抽成群組**。

```php
Route::group('/media', function () {
    Route::get('/', ['as' => 'index', 'uses' => MediaController::class . '@index']);
    Route::post('/', ['as' => 'store', 'uses' => MediaController::class . '@store']);
    Route::get('/{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}', [
        'as' => 'show', 'uses' => MediaController::class . '@show',
    ]);

    // 子資源：參數段只提供 prefix，name 段由子資源自己帶
    Route::group('/{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}/summaries', function () {
        Route::get('/', ['as' => 'index', 'uses' => SummariesController::class . '@index']);
    }, ['as' => 'summaries']);
}, ['as' => 'media', 'middleware' => ['auth']]);
```

### 靜態段必須在同層動態段之前

`Route::get('/usage', ...)` 必須寫在 `Route::get('/{subscriptionId}', ...)` **之前**。
動態段若沒有格式約束保護（例如 `{subscriptionId}` 沒有 ULID pattern），順序是正確性的**唯一**保障。

### 參數約束就地宣告

Hypervel 沒有 `Route::pattern()` 這種全域約束，**只能寫在路徑字串裡**：

```php
'/{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}'   // ULID
'/{userId:[0-9]+}'                          // 數字主鍵
```

同一個 pattern 在多處重複是這個 router 的先天限制，不要為了消除重複而改用
無約束的 `{mediaId}`——約束消失後任何字串都會進到 Controller。

### name 段組合原則

route name 由各層 `'as'` 串接而成，重構**不得改變任何最終名稱**。
純粹用來加 prefix、本身不對應語意層級的群組（如 `{mediaId}` 參數群組），
**不加 `'as'` 段**，子路由直接帶完整 action 名。

## 重構驗收

重構路由結構（不改行為）時，必須證明 method / uri 完全不變：

```bash
# 重構前後各跑一次，diff 應為空
docker compose exec hypervel php artisan route:list > /tmp/routes-before.txt
```

route name 沒有出現在 `route:list` 輸出中，改用 `route()` 逐一驗證：

```bash
docker compose exec hypervel php artisan tinker \
    --execute="echo route('api.v1.auth.register.store');"
```

並執行對應的 Feature 測試套件確認零行為差異（測試本身就是以 `route()` 取 URI）。
