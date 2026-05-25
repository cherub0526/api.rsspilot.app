## Why

現有的 `GET /v1/sources` 列表端點只回傳已訂閱來源的基本欄位（`id`、`name`、`url`、`type`、`notify`、`thumbnail`）。前端需要單一來源的詳細檢視，能取得 `description` 與 `subscriber_count`，而不必每次都載入整份清單。

## What Changes

- 新增 `GET /v1/sources/{sourceId}` 端點，回傳單一來源的完整詳細資訊
- 此端點額外揭露列表端點所沒有的兩個欄位：`description` 與 `subscriber_count`
- 存取控制：來源為免費（`Source::free = true`）**或**已驗證使用者已訂閱時才可存取，否則回傳 404

## Capabilities

### New Capabilities

- `sources-show`：透過 ULID 取得單一來源的詳細資訊，包含 `description` 與 `subscriber_count`

### Modified Capabilities

<!-- 無現有規格層級的需求異動 -->

## Impact

- **路由**：`routes/v1.php` — 在 `sources` 群組內新增 `GET /{sourceId}` 路由
- **Controller**：`app/Http/Controllers/API/V1/SourcesController.php` — 新增含 OAT 註解的 `show()` 方法
- **Resource**：新建 `app/Http/Resources/SourceDetailResource.php` 類別
- **OpenAPI**：在 `show()` 註解中定義 inline schema；更新 `public/openapi.json`
