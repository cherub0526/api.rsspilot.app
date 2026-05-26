## 1. Database & Model Foundation

- [x] 1.1 建立 `database/migrations/2026_05_27_100000_create_user_avatars_table.php`（ulid PK, user_id, filename, path, timestamps）
- [x] 1.2 執行 `php artisan migrate` 確認 migration 成功
- [x] 1.3 建立 `app/Models/UserAvatar.php`（HasUlids, fillable: user_id/filename/path, user() BelongsTo）
- [x] 1.4 在 `app/Models/User.php` 將 `avatar` 加入 `$fillable`，新增 `avatars(): HasMany` 關聯

## 2. Config & Environment

- [x] 2.1 在 `config/app.php` 的 `url` 項目後加入 `'cdn_url' => env('CDN_URL', '')`
- [x] 2.2 在 `.env.example` 的 `AWS_URL=` 後加入 `CDN_URL=`

## 3. UserResource Avatar 欄位（TDD）

- [x] 3.1 建立 `tests/Unit/Resources/UserResourceAvatarTest.php`（3 個 unit tests：有 avatar 時回 CDN URL、無 avatar 時回 null、trailing slash 正規化）
- [x] 3.2 執行測試確認 FAIL（`UserResource` 尚無 avatar 欄位）
- [x] 3.3 更新 `app/Http/Resources/UserResource.php`，在 `toArray()` 加入 `avatar` CDN URL 欄位
- [x] 3.4 更新 `app/OpenApi/Schemas/UserResource.php`，加入 `avatar` nullable string property
- [x] 3.5 執行測試確認 3 tests PASS

## 4. AvatarValidator（TDD）

- [x] 4.1 建立 `tests/Unit/Validators/AvatarValidatorTest.php`（2 個 unit tests：缺 file 時 fail、rules 含 required/image/max:2048）
- [x] 4.2 執行測試確認 FAIL（class 不存在）
- [x] 4.3 建立 `app/Validators/AvatarValidator.php`（rule: required|file|image|mimes:jpeg,png,jpg,webp|max:2048）
- [x] 4.4 執行測試確認 2 tests PASS

## 5. AvatarController + Route（TDD）

- [x] 5.1 建立 `tests/Feature/API/V1/Users/AvatarControllerTest.php`（7 個 feature tests：401/422 驗證、成功上傳回 UserResource、DB 紀錄、重複上傳歷史）
- [x] 5.2 執行測試確認全部 FAIL（route 不存在）
- [x] 5.3 在 `routes/v1.php` 加入 `use App\Http\Controllers\API\V1\Users\AvatarController` 及 `POST /avatar` 路由
- [x] 5.4 建立 `app/Http/Controllers/API/V1/Users/AvatarController.php`（validate → uuid path → S3 put → UserAvatar::create → user update → UserResource）
- [x] 5.5 執行 feature tests 確認 7 tests PASS
- [x] 5.6 執行全部測試套件確認無 regression（`vendor/bin/phpunit --testdox`）

## 6. Code Quality & Commit

- [x] 6.1 執行 `composer cs-diff` 修正 code style
- [x] 6.2 執行 `composer analyse` 確認 PHPStan 無警告（既有錯誤 86 個，新增檔案零錯誤）
- [x] 6.3 Commit 所有變更
