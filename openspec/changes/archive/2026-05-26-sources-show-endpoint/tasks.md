## 1. Resource

- [x] 1.1 建立 `app/Http/Resources/SourceDetailResource.php`，實作獨立的 `toArray()`，包含全部 8 個欄位：`id`、`name`、`url`、`type`、`notify`、`thumbnail`、`description`、`subscriber_count`
- [x] 1.2 確保 `description` 在 DB 值為 null 時回傳 `""`
- [x] 1.3 確保 `subscriber_count` 對頻道來源回傳整數（預設 `0`），對播放清單來源回傳 `null`

## 2. Controller

- [x] 2.1 在 `app/Http/Controllers/API/V1/SourcesController.php` 新增 `show(string $sourceId): ResponseInterface` 方法
- [x] 2.2 實作存取控制查詢：當 `Source::free = true` 或來源在已驗證使用者的訂閱中時才載入；否則 abort 404
- [x] 2.3 以 `response()->json()` 包裝 `SourceDetailResource` 後回傳
- [x] 2.4 在 `show()` 方法上加入含 inline 回應 schema 的 OAT 註解（`#[OAT\Get(...)]`）

## 3. Route

- [x] 3.1 在 `routes/v1.php` 的 `sources` 群組內新增 `GET /{sourceId}` 路由，並套用 ULID 正規表示式限制 `[0-7][0-9a-hjkmnp-tv-z]{25}`
- [x] 3.2 確認新路由與群組內現有路由沒有衝突

## 4. OpenAPI

- [x] 4.1 重新產生 `public/openapi.json`，確認新的 `GET /v1/sources/{sourceId}` 操作已出現且請求／回應 schema 正確

## 5. Tests

- [x] 5.1 撰寫功能測試：已訂閱使用者可取得來源（200 + 欄位正確）
- [x] 5.2 撰寫功能測試：已驗證使用者可取得免費來源（200）
- [x] 5.3 撰寫功能測試：來源不在訂閱中且非免費時回傳 404
- [x] 5.4 撰寫功能測試：未驗證請求回傳 401
- [x] 5.5 撰寫功能測試：`sourceId` 格式無效時回傳 404
- [x] 5.6 撰寫單元測試：`SourceDetailResource` 對 null description 回傳 `""`
- [x] 5.7 撰寫單元測試：`SourceDetailResource` 對播放清單類型的 `subscriber_count` 回傳 `null`
