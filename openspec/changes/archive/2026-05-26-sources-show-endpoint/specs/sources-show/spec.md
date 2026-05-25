## ADDED Requirements

### Requirement: 透過 ID 取得單一來源詳細資訊
系統 SHALL 提供 `GET /v1/sources/{sourceId}` 端點，當已驗證使用者有存取權限時，回傳單一來源的詳細資訊。

#### Scenario: 已訂閱使用者取得自己的來源
- **WHEN** 已驗證使用者發送 `GET /v1/sources/{sourceId}`，且 `sourceId` 在其已訂閱來源中
- **THEN** 系統回傳 HTTP 200，JSON 主體包含 `id`、`name`、`url`、`type`、`notify`、`thumbnail`、`description`、`subscriber_count`

#### Scenario: 已驗證使用者取得免費來源
- **WHEN** 已驗證使用者發送 `GET /v1/sources/{sourceId}`，且該來源的 `free = true`
- **THEN** 系統回傳 HTTP 200，無論使用者是否已訂閱該來源

#### Scenario: 來源不存在或無存取權限
- **WHEN** 已驗證使用者發送 `GET /v1/sources/{sourceId}`，且該來源不存在，或既非免費亦不在使用者訂閱中
- **THEN** 系統回傳 HTTP 404

#### Scenario: 未驗證的請求
- **WHEN** 發送 `GET /v1/sources/{sourceId}` 時未帶有效 JWT token
- **THEN** 系統回傳 HTTP 401

### Requirement: 來源詳細回應欄位
系統 SHALL 在回應主體中回傳包含下列欄位的來源詳細資訊。

#### Scenario: 頻道來源所有欄位均有值
- **WHEN** 來源類型為 `channel` 且所有欄位均已填入
- **THEN** 回應 SHALL 包含：`id`（ULID 字串）、`name`（字串）、`url`（YouTube 頻道 URL，由系統推導）、`type`（`"channel"`）、`notify`（布林值，來自使用者訂閱的 pivot 欄位）、`thumbnail`（字串或 null）、`description`（字串，DB 為 null 時回傳空字串）、`subscriber_count`（整數，metadata 中不存在時退回 `0`）

#### Scenario: 播放清單來源的 subscriber_count
- **WHEN** 來源類型為 `playlist`
- **THEN** 回應中的 `subscriber_count` SHALL 為 `null`

#### Scenario: 資料庫中 description 為 null
- **WHEN** 來源的 `description` 欄位在資料庫中為 null
- **THEN** 回應 SHALL 將 `description` 回傳為空字串 `""`

### Requirement: 路徑參數驗證
系統 SHALL 將 `sourceId` 路徑參數驗證為 ULID 格式。

#### Scenario: sourceId 格式無效
- **WHEN** 請求中的 `sourceId` 不符合 ULID 模式 `[0-7][0-9a-hjkmnp-tv-z]{25}`
- **THEN** 系統回傳 HTTP 404（路由不匹配）
