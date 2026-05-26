## ADDED Requirements

### Requirement: Authenticated user can upload avatar
已認證使用者 SHALL 能透過 `POST /v1/users/avatar`（multipart/form-data，欄位名 `file`）上傳頭像圖片，並取得含 CDN URL 的完整使用者資料作為回應。

#### Scenario: Successful avatar upload
- **WHEN** 已認證使用者 POST `multipart/form-data` 含有效圖片檔至 `/v1/users/avatar`
- **THEN** 系統回傳 HTTP 200 及含 `avatar` CDN URL 的 UserResource

#### Scenario: Unauthenticated request is rejected
- **WHEN** 未帶 JWT token 的請求 POST 至 `/v1/users/avatar`
- **THEN** 系統回傳 HTTP 401

### Requirement: Avatar file validation
系統 SHALL 驗證上傳檔案格式為 `jpeg`, `png`, `jpg`, `webp`，且大小不超過 2 MB；違反規則時回傳 422。

#### Scenario: Missing file is rejected
- **WHEN** 已認證使用者 POST 空 body 至 `/v1/users/avatar`
- **THEN** 系統回傳 HTTP 422，且 response body 含 `messages.file` 錯誤

#### Scenario: Non-image file is rejected
- **WHEN** 已認證使用者上傳非圖片格式（如 `.txt`）
- **THEN** 系統回傳 HTTP 422，且 response body 含 `messages.file` 錯誤

#### Scenario: Oversized file is rejected
- **WHEN** 已認證使用者上傳超過 2 MB 的圖片
- **THEN** 系統回傳 HTTP 422，且 response body 含 `messages.file` 錯誤

### Requirement: Avatar stored in S3 with unique UUID filename
系統 SHALL 以 `avatars/{userId}/{uuid}.{ext}` 格式將圖片上傳至 S3，其中 `uuid` 為每次上傳獨立生成的 UUID v4。

#### Scenario: File stored with unique path
- **WHEN** 使用者成功上傳頭像
- **THEN** S3 中存在路徑符合 `avatars/{userId}/{uuid}.{ext}` 格式的檔案

### Requirement: Avatar upload history recorded in user_avatars
每次成功上傳 SHALL 在 `user_avatars` 資料表新增一筆紀錄，包含 `user_id`、`filename`（客戶端原始檔名）、`path`（S3 相對路徑）。

#### Scenario: First upload creates one record
- **WHEN** 使用者第一次成功上傳頭像
- **THEN** `user_avatars` 資料表有一筆記錄，`filename` 為客戶端原始檔名，`path` 以 `avatars/{userId}/` 開頭

#### Scenario: Re-upload creates additional record
- **WHEN** 使用者第二次成功上傳頭像
- **THEN** `user_avatars` 資料表有兩筆記錄，舊記錄仍存在，`users.avatar` 更新為新 path

### Requirement: Current avatar path reflected in users.avatar
成功上傳後，系統 SHALL 更新 `users.avatar` 為最新的 S3 相對路徑。

#### Scenario: users.avatar updated after upload
- **WHEN** 使用者成功上傳頭像
- **THEN** `users.avatar` 欄位值與最新 `user_avatars.path` 相同

#### Scenario: Re-upload updates users.avatar to new path
- **WHEN** 使用者再次上傳頭像
- **THEN** `users.avatar` 更新為新 path，舊 path 仍保留於 `user_avatars` 歷史紀錄
