# User Profile

## Purpose

定義使用者個人資料相關的資料結構與回應格式，包含 `UserResource` 的欄位組成。

## Requirements

### Requirement: UserResource includes avatar CDN URL
`UserResource` 的 `toArray()` 回應 SHALL 包含 `avatar` 欄位。當 `users.avatar` 有值時，`avatar` 為完整 CDN URL（`CDN_URL` + `/` + S3 相對路徑）；當 `users.avatar` 為 null 時，`avatar` 回傳 `null`。

#### Scenario: User with avatar returns CDN URL
- **WHEN** 使用者的 `users.avatar` 為非空字串（如 `avatars/01J.../uuid.jpg`）
- **THEN** `UserResource.avatar` 為 `https://{CDN_URL}/avatars/01J.../uuid.jpg`

#### Scenario: User without avatar returns null
- **WHEN** 使用者的 `users.avatar` 為 null
- **THEN** `UserResource.avatar` 為 `null`

#### Scenario: CDN URL trailing slash is normalized
- **WHEN** `CDN_URL` 環境變數結尾含斜線（如 `https://cdn.example.com/`）
- **THEN** 組合後的 URL 不含雙斜線（如 `https://cdn.example.com/avatars/...`）
