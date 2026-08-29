---
paths:
  - "app/OpenApi/**"
  - "app/Http/Controllers/**"
  - "app/Http/Resources/**"
---

# OpenAPI 規則

> Schema 放置原則的實作細節（workflow、範例、PSR-4 命名）請用 `/openapi-php-docs` skill。

## 核心約束

- 使用 PHP 8 **Attributes**（`#[OAT\...]`），禁止舊式 `@OA\` Annotations
- **所有 Schema 一律放 `app/OpenApi/Schemas/`**，檔名與對應的 Resource 同名
  （`app/Http/Resources/MediaResource.php` → `app/OpenApi/Schemas/MediaResource.php`）
- **不要**把 `#[OAT\Schema]` 標在 `app/Http/Resources/*.php` 的類別上
- Controller 引用時以 alias 避免撞名：
  `use App\OpenApi\Schemas\MediaResource as MediaSchema;` → `ref: MediaSchema::class`
- 可重用的 parameter / response 放 `app/OpenApi/Parameters/`、`app/OpenApi/Responses/`，
  以 `ref:` 引用，不要在 Controller 內重複展開
- `public/openapi.json` 是自動產生的，**絕對不手動編輯**
- 修改後必須執行 `composer openapi` 重新產生

## `operationId` 等於 route name

每個端點的 `operationId` 必須與 `routes/v1.php` 推導出的 route name 逐字相同：

```php
#[OAT\Get(
    path: '/v1/users',
    operationId: 'api.v1.users.index',
    ...
)]
public function index(Request $request): UserResource
```

route name 的推導規則見 `routes.md`。改路由名時 `operationId` 要同步改。

## 掃描範圍

```bash
docker compose exec hypervel composer openapi
```

實際指令是 `openapi app -o public/openapi.json`——**掃整個 `app/` 目錄**，
所以 attribute 放在 `app/` 底下任何位置都會被收進去。
