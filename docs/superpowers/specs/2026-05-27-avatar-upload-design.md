# Avatar Upload Design

**Date:** 2026-05-27  
**Status:** Approved  

## Overview

新增 `POST /v1/users/avatar` 端點，讓已登入使用者透過 `multipart/form-data` 上傳個人頭像。檔案儲存於 S3，透過 CloudFront CDN 提供存取 URL，每次上傳均在 `user_avatars` 資料表保留歷史紀錄。

---

## API 契約

```
POST /v1/users/avatar
Content-Type: multipart/form-data
Authorization: Bearer {token}

Body:
  file: <binary>   // 圖片檔案（必填）
```

### 檔案限制
- 格式：`jpeg`, `png`, `jpg`, `webp`
- 大小：最大 2 MB

### 回應
- **200 OK**：完整 `UserResource`，含 `avatar` CDN URL
- **400 Bad Request**：驗證失敗
- **401 Unauthorized**：未登入

---

## 資料流

```
Client
  │  POST /v1/users/avatar (multipart)
  ▼
AvatarController@store
  ├─ 驗證 file（required|file|image|mimes:jpeg,png,jpg,webp|max:2048）
  ├─ $originalFilename = $file->getClientFilename()
  ├─ 產生 UUID → $path = "avatars/{userId}/{uuid}.{ext}"
  ├─ Storage::disk('s3')->put($path, file_get_contents($file->getRealPath()))
  ├─ UserAvatar::create(['user_id', 'filename', 'path'])
  ├─ User::update(['avatar' => $path])
  └─ return new UserResource($user)
```

### CDN URL 組合

```php
// UserResource
'avatar' => $this->resource->avatar
    ? rtrim(config('app.cdn_url'), '/') . '/' . $this->resource->avatar
    : null,
```

---

## 資料庫

### `user_avatars` 資料表（新增 migration）

| 欄位 | 型別 | 說明 |
|------|------|------|
| `id` | ulid | PK |
| `user_id` | ulid | FK → users.id |
| `filename` | string | 客戶端原始檔名，e.g. `my-photo.jpg` |
| `path` | string | S3 相對路徑，e.g. `avatars/{userId}/{uuid}.jpg` |
| `created_at` | timestamp | 上傳時間 |
| `updated_at` | timestamp | — |

### `users` 資料表（無需 migration）

- `users.avatar` 欄位已存在（nullable string），永遠指向最新的 S3 path

### 設計原則
- **`users.avatar`**：當前頭像的 S3 path，快速查詢用
- **`user_avatars`**：所有上傳歷史紀錄（含舊檔名）
- **S3 檔案**：舊檔案不刪除，搭配 S3 lifecycle policy 管理

---

## 新增檔案

```
app/Http/Controllers/API/V1/Users/AvatarController.php
app/Models/UserAvatar.php
app/Validators/AvatarValidator.php
database/migrations/YYYY_MM_DD_XXXXXX_create_user_avatars_table.php
```

## 修改檔案

```
app/Models/User.php                    — 加 avatar 到 $fillable、加 avatars() HasMany
app/Http/Resources/UserResource.php   — 加 avatar CDN URL
routes/v1.php                         — 加路由
config/app.php                        — 加 cdn_url
.env.example                          — 加 CDN_URL
```

---

## 路由

```php
// routes/v1.php
Route::group('/users', function () {
    // 現有路由不變

    Route::post('/avatar', [
        'as'         => 'avatar.store',
        'uses'       => AvatarController::class . '@store',
        'middleware' => ['auth'],
    ]);
}, ['as' => 'users']);
```

---

## 環境變數

```dotenv
# .env.example
CDN_URL=https://cdn.example.com
```

`config/app.php` 補充：
```php
'cdn_url' => env('CDN_URL', ''),
```

---

## 驗證

`App\Validators\AvatarValidator::setStoreRules()`：

```php
$this->rules = [
    'file' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:2048',
];
```

---

## 測試計畫

檔案：`tests/Feature/Users/AvatarControllerTest.php`

| 測試案例 | 預期結果 |
|---------|---------|
| `test_unauthenticated_user_cannot_upload_avatar` | 401 |
| `test_upload_avatar_requires_file` | 400 |
| `test_upload_avatar_validates_file_type` | 400（非圖片格式） |
| `test_upload_avatar_validates_file_size` | 400（超過 2MB） |
| `test_authenticated_user_can_upload_avatar` | 200，回傳 UserResource 含 avatar CDN URL |
| `test_upload_saves_user_avatar_record` | `user_avatars` 新增一筆，filename & path 正確 |
| `test_reuploading_creates_new_avatar_record` | 建立第二筆 `user_avatars`，`users.avatar` 更新為新路徑 |

---

## 架構決策

- **獨立 `AvatarController`**：遵循專案子 controller 模式（`Media/ChatController`、`Sources/MediasController`），單一職責
- **不刪除舊 S3 檔案**：由 S3 lifecycle policy 統一管理，避免競態條件
- **CDN URL 動態組合**：`users.avatar` 僅儲存相對路徑，切換 CDN domain 時不需更新 DB
- **UUID 檔名**：確保唯一性，避免覆蓋，搭配 `user_avatars.filename` 保留原始名稱
