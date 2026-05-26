## Why

使用者目前無法上傳個人頭像，個人資料頁面缺乏視覺識別。透過新增頭像上傳端點，讓使用者能自訂個人形象，同時保留所有上傳歷史紀錄以利稽核與追蹤。

## What Changes

- 新增 `POST /v1/users/avatar` 端點，接受 `multipart/form-data` 上傳頭像圖片
- 圖片上傳至 S3，以 UUID 唯一檔名儲存，透過 CloudFront CDN 提供存取
- 每次上傳在 `user_avatars` 資料表保留歷史紀錄（含原始檔名與 S3 路徑）
- `users.avatar` 欄位永遠指向最新頭像的 S3 相對路徑
- `UserResource` 新增 `avatar` 欄位，動態組合 CDN 完整 URL

## Capabilities

### New Capabilities

- `user-avatar-upload`: 使用者頭像上傳功能，包含 S3 儲存、CDN URL 回傳、歷史紀錄追蹤

### Modified Capabilities

- `user-profile`: `UserResource` 回應新增 `avatar` CDN URL 欄位；`users` 表現有 `avatar` 欄位納入 `$fillable`

## Impact

- **新增 API 端點**：`POST /v1/users/avatar`（需 JWT 認證）
- **新增資料表**：`user_avatars`（ULID PK，欄位：user_id, filename, path）
- **修改 Model**：`User`（$fillable + avatars() 關聯）、新增 `UserAvatar` model
- **修改 Resource**：`UserResource` 加入 avatar CDN URL
- **新增 Validator**：`AvatarValidator`（file|image|mimes:jpeg,png,jpg,webp|max:2048）
- **新增 Controller**：`Users/AvatarController`（子 controller 模式）
- **環境變數**：新增 `CDN_URL`，config/app.php 加入 `cdn_url`
- **S3**：舊檔案不刪除，由 S3 lifecycle policy 管理；新檔名格式：`avatars/{userId}/{uuid}.{ext}`
