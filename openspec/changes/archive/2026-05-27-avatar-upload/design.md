## Context

本專案為 Hypervel（Laravel-compatible）API，現有 `users.avatar` 欄位（nullable string）已存在但尚未被 `$fillable` 採用，也未透過任何端點開放上傳。檔案儲存已有 S3 infrastructure（`config/filesystems.php` 含 s3 disk），且 `FeedbacksController` 已有 multipart 上傳至 S3 的 pattern 可參考。CDN 目前無 config 條目，需新增。

## Goals / Non-Goals

**Goals:**
- 提供 `POST /v1/users/avatar` 端點，讓已認證使用者上傳頭像
- 將每次上傳記錄於 `user_avatars` 資料表（含原始檔名與 S3 path）
- `users.avatar` 永遠反映最新頭像 path
- `UserResource.avatar` 動態組合 CDN 完整 URL 回傳給前端
- S3 舊檔保留，不自動刪除

**Non-Goals:**
- 圖片裁切或尺寸壓縮（前端處理）
- 刪除頭像端點
- 頭像歷史查詢端點
- CDN Cache Invalidation（由 lifecycle policy 處理）

## Decisions

### 1. 獨立子 Controller（`Users/AvatarController`）

**決策**：新建 `app/Http/Controllers/API/V1/Users/AvatarController.php`，不在 `UsersController` 加方法。

**理由**：專案現有 `Media/ChatController`、`Sources/MediasController` 均採子 controller 模式，multipart 上傳邏輯與 JSON profile 更新屬不同職責，應分離。

**棄選**：在 `UsersController` 新增 `avatar()` 方法 — 違反單一職責，且隨檔案增大難以維護。

### 2. UUID 唯一檔名，路徑格式 `avatars/{userId}/{uuid}.{ext}`

**決策**：每次上傳以 `Str::uuid()` 產生唯一 UUID 作為檔名，並以 `userId` 作為目錄層級。

**理由**：確保唯一性，防止覆蓋；`userId` 前綴方便 S3 lifecycle policy 按使用者清理。

**棄選**：沿用原始檔名 — 同名檔案會覆蓋；Hash 檔名 — 無法判斷上傳時間序。

### 3. 保留舊 S3 檔案，歷史紀錄存入 `user_avatars`

**決策**：上傳新頭像時不刪除舊 S3 檔案；每次上傳在 `user_avatars` 新增一筆（user_id, filename, path）。

**理由**：避免競態條件（CDN cache 可能仍在使用舊 URL）；稽核需求；S3 lifecycle policy 可統一管理過期清理。

**棄選**：覆蓋刪除舊檔 — 實作複雜且有競態風險；只更新 `users.avatar` 不留歷史 — 無法追蹤。

### 4. CDN URL 動態組合，DB 只存相對 path

**決策**：`users.avatar` 與 `user_avatars.path` 僅儲存 S3 相對路徑（如 `avatars/01J.../uuid.jpg`）；`UserResource` 在回傳時組合 `CDN_URL` + path。

**理由**：日後切換 CDN domain 只需改環境變數，不需 DB migration。

**棄選**：直接儲存完整 CDN URL — CDN 換域時需大量 DB update。

## Risks / Trade-offs

- **S3 儲存成長**：舊檔案永不刪除，長期 S3 成本上升 → 設定 S3 lifecycle policy 自動清理超過 N 天的 `avatars/` 物件
- **CDN_URL 未設定**：若 `.env` 未設 `CDN_URL`，`UserResource.avatar` 會回傳不完整 URL → 部署 checklist 需確認此環境變數
- **Hypervel PSR-7 file wrapper**：`$file->getClientFilename()` 為 PSR-7 interface，與 Laravel `getClientOriginalName()` 不同，測試中的 `UploadedFile::fake()` 可能行為差異 → AvatarController 加入 fallback 處理

## Migration Plan

1. Deploy 前確認 `.env` 已設定 `CDN_URL`、`AWS_*` 相關變數
2. 執行 `php artisan migrate`（新增 `user_avatars` 資料表）
3. Deploy 應用程式
4. Rollback：`php artisan migrate:rollback`（drop `user_avatars`）；`users.avatar` 欄位本已存在，無需額外處理

## Open Questions

- S3 lifecycle policy 的清理期限由 DevOps 決定（建議 90 天）
- 是否需要後台查看使用者頭像歷史？目前不在 scope，未來可加 `GET /v1/users/avatars`
