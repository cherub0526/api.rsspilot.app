---
paths:
  - "routes/**"
  - "app/Http/Controllers/**"
  - "app/Http/Resources/**"
  - "app/Validators/**"
---

# API 開發規則（Controller / Response / 錯誤處理）

> 本專案是 **Hypervel**（Laravel 風格、跑在 Swoole 協程上）。多數 Laravel 慣例可直接套用，
> 但 HTTP 層走 PSR-7、驗證層走自製 Validator，與原生 Laravel 不同——本檔只寫「不一樣的地方」
> 與專案自訂的約束。路由命名見 `routes.md`，驗證見 `validators.md`，輸出格式見 `resources.md`。

## Controller 設計

- 一律繼承 `App\Http\Controllers\AbstractController`
- Controller 只負責：接 request → 驗證 → 呼叫 Service/Model → 回傳 Resource/Response
- 業務邏輯放 `app/Services/`、Model scope 或 Model 方法，**不放 Controller**
- 每個 public method 不超過 50 行
- Request 型別提示用 `Hypervel\Http\Request`（**不是** `Illuminate\Http\Request`）

```php
use Hypervel\Http\Request;
use App\Http\Controllers\AbstractController;

class FeedbacksController extends AbstractController
{
    public function store(Request $request): ResponseInterface
    {
        // ...
    }
}
```

## 回傳型別

兩種都合法，依情況擇一：

| 回傳 | 用在 | 型別提示 |
|------|------|---------|
| Resource 物件 | 單筆或列表資源 | `UserResource` / `AnonymousResourceCollection` |
| PSR-7 Response | 需要自訂 status code、非資源內容 | `\Psr\Http\Message\ResponseInterface` |

```php
// ✅ 直接回傳 Resource（框架會自動序列化）
public function index(Request $request): UserResource
{
    return new UserResource($request->user()->load(['setting']));
}

// ✅ 列表
public function index(Request $request): AnonymousResourceCollection
{
    return SourceResource::collection($sources);
}

// ✅ 需要 status code 或非資源內容時用 PSR-7
public function destroy(Request $request, string $id): ResponseInterface
{
    return response()->json(['message' => self::RESPONSE_OK]);
}
```

**不要**回傳 `Illuminate\Http\JsonResponse`——本框架的 response 走 PSR-7。

## 認證

- JWT guard：`auth('jwt')` / `Auth::guard('jwt')`；`$request->user()` 在掛了 `auth` middleware 的路由上可直接取得使用者
- **middleware 寫在 routes 檔**（`'middleware' => ['auth']`），不在 Controller 內設定——見 `routes.md`
- 不是所有路由都要 `auth`：webhook 與 `plans.index` 就是公開的

## Request Validation

**本專案不用 Form Request**（`app/Http/Requests/` 只剩一個未使用的範例檔）。
一律走 `app/Validators/` 的 instance-based Validator：

```php
$validator = (new UserValidator($request->only(['name', 'email'])))->setUpdateRules();

if (!$validator->passes()) {
    throw new InvalidRequestException($validator->errors()->toArray());
}
```

規則撰寫、訊息命名、lang 檔對應見 `validators.md`。

## 錯誤處理

- 驗證錯誤 → `throw new App\Exceptions\InvalidRequestException($errors)`
- 業務錯誤訊息一律走語系檔：`__('validators.controllers.{domain}.{key}')`
  （`controllers.*` 這層專門放 Controller 主動拋出的訊息，與欄位驗證訊息分開）
- 統一由 `App\Exceptions\Handler` 收斂，**不要**在 Controller 內 `try/catch` 後自行組 500 回應
- 不把 DB error、stack trace、第三方 API 原始回應洩漏給 client

```php
// ✅
if ($media === null) {
    throw new InvalidRequestException(__('validators.controllers.media.not_found'));
}
```

## 協程注意事項

Swoole 是長生命週期的常駐程序，Controller 由容器解析後可能被重複使用：

- **不要**在 Controller 用 property 存 per-request 狀態（`$this->user = ...`）
- 需要的資料每次從 `$request` 取
- 不要在 Controller constructor 注入帶狀態的 Service

## OpenAPI

每個對外端點都要有 `#[OAT\...]` attribute，`operationId` 與 route name 一致
（`api.v1.users.index`）。細節見 `openapi.md`。
