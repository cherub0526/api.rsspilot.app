# Validator 命名規範與 Domain 分層

## 類別命名

| 元素 | 規則 | 範例 |
|------|------|------|
| 檔案名稱 | `{Domain}Validator.php` | `CourseValidator.php` |
| 類別名稱 | `{Domain}Validator` | `CourseValidator` |
| Namespace | `App\Validators` | `App\Validators` |

`{Domain}` 為 **PascalCase 單數名詞**，對應 Controller 的業務領域。

## set*Rules() 命名

動詞或動作名稱 + `Rules` 後綴。優先選用以下慣用詞：

| 場景 | 推薦命名 |
|------|---------|
| 新增資源（POST） | `setStoreRules` |
| 整體更新（PUT） | `setUpdateRules` |
| 部分更新（PATCH） | `setPatchRules` |
| 使用者註冊 | `setRegisterRules` |
| 使用者登入 | `setLoginRules` |
| 重設密碼 | `setResetPasswordRules` |
| 忘記密碼 | `setForgotPasswordRules` |
| 寄送驗證信 | `setResendVerificationRules` |
| 搜尋 / 篩選 | `setSearchRules` |
| 批量操作 | `setBulkRules` |

若以上都不適用，用 **動詞 + 名詞** 描述動作（e.g., `setImportRules`、`setPublishRules`）。

## Domain 分層原則

一個 Validator 類別對應 **一個業務 domain**，而非一個 Controller。

```
✓ 同一 Controller 的多個 method 共用一個 Validator
  CourseController::store()  → new CourseValidator → setStoreRules()
  CourseController::update() → new CourseValidator → setUpdateRules()

✓ 多個 Controller 可以共用一個 domain Validator（若業務域相同）
  AdminCourseController → new CourseValidator → setStoreRules()
  ApiCourseController  → new CourseValidator → setStoreRules()

✗ 不要為每個 Controller method 建立獨立的 Validator 類別
  StoreCourseValidator、UpdateCourseValidator ← 過度拆分
```

## 目錄結構範例

```
app/Validators/
├── BaseValidator.php
├── AuthValidator.php           ← 覆蓋 store / register / social / reset...
├── MediaValidator.php          ← 覆蓋 index / show / chat...
├── RSSValidator.php            ← 覆蓋 store / destroy...
├── UserValidator.php           ← 覆蓋 update（profile）...
└── SubscriptionValidator.php   ← 覆蓋 store / update / cancel...
```

## 版本策略

所有 Validator 放在 `app/Validators/`（flat，無版本子目錄）。若 V2 API 的規則有**破壞性差異**，在同一類別新增帶版本後綴的 `set*V2Rules()` 方法；若差異過大，另建 `{Domain}V2Validator.php` 並保留舊類別。

## 禁止事項

- 禁止在 `set*Rules()` 中 `return array` — 必須呼叫 `$this->make()`
- 禁止在 Service 層引用 Validator 類別（Service 不依賴 HTTP 輸入層）
- 禁止修改 `BaseValidator.php`（架構層級，需另行討論）
- 禁止把 Validator 注入為 singleton（每次請求必須是新 instance，直接 `new` 建立）
