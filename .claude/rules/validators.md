---
paths:
  - "app/Validators/**"
  - "lang/*/validators.php"
---

# Validator 規則

> 實作 workflow（建檔、串接 Controller、寫測試的步驟）見 `/hypervel-validator` skill。
> 本檔規範的是 skill 沒有涵蓋的部分：**目錄與命名、語系 key 格式、規則內容的判準**。

## 基礎結構

所有 Validator 繼承 `App\Validators\BaseValidator`。每個「動作」對應一個 `set{Action}Rules()`
方法，不混用規則。

```
app/Validators/
  BaseValidator.php          ← 基礎類別，不要改
  {Feature}Validator.php     ← 一個 Feature 一個檔案，扁平放置
```

**本專案沒有 Group 子目錄**（不是 `app/Validators/V1/`）。所有路由共用同一個 `App\Validators`
命名空間，Feature 名稱直接對應資源：`UserValidator`、`MediaValidator`、`SourceValidator`。

## Constructor 與多語系

`BaseValidator::__construct()` 只接受 `$params`，語系由 `App\Http\Middleware\SetLocale`
依請求設定，Validator 不收 locale 參數。

新增的 Validator 固定簽名為 `public function __construct(array $params)`，
第一行必須呼叫 `parent::__construct($params)`：

```php
class SourceValidator extends BaseValidator
{
    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'url.required' => __('validators.source.url.required'),
            'url.string'   => __('validators.source.url.string'),
        ];
    }
}
```

## 訊息集中在 `__construct()`

具體 Validator 在 `__construct()` 設定**該 Validator 所有規則**的訊息，
`set{Action}Rules()` 只負責 `$this->rules` 並 `return $this`：

```php
public function setStoreRules(): self
{
    $this->rules = [
        'url'    => 'required|string|url',
        'notify' => 'sometimes|boolean',
    ];

    return $this;   // 不在此設定 messages
}
```

**原則：**
- `__construct()` 集中定義所有訊息，讓同一個 Validator 的所有 `set{Action}Rules()` 共用
- 只設定有意義的欄位（`required` 必設，`in` 建議設，其他視需要）
- 規則用 pipe 字串（`'required|string|max:255'`）；規則本身含 `|`（如 `regex:`）時才改用陣列

## 多語系訊息命名規則

```
validators.{feature}.{field}.{rule}
```

| 部分 | 說明 | 範例 |
|------|------|------|
| `validators` | 固定前綴 | `validators` |
| `{feature}` | lang 檔中對應此 Validator 的段落名 | `source`、`media`、`settings` |
| `{field}` | 欄位名稱（陣列欄位去掉 `*`） | `url`、`items`、`items.source` |
| `{rule}` | 驗證 rule 名稱 | `required`、`in`、`integer` |

> **`{feature}` 先查 lang 檔再決定，不要從類別名硬推。** 既有段落名並不完全一致：
> `SettingValidator` 對應的是 `validators.settings`（複數），`SourceValidator` 對應
> `validators.source`（單數）。沿用既有段落，新 Feature 才用類別名去掉 `Validator`
> 後的小寫 snake_case（`YoutubeMp3DownloaderValidator` → `youtube_mp3_downloader`）。

陣列欄位：validator messages 的 key 是 `items.*.source.required`，
對應翻譯 key 把 `*` 省略，寫成 `items.source.required`。

### `controllers.*` 是另一回事

`validators.controllers.{domain}.{key}` 這一層放的是 **Controller 主動拋出的業務訊息**
（找不到資源、超出方案上限…），不是欄位驗證訊息，也沒有 `{field}.{rule}` 結構：

```php
throw new InvalidRequestException(__('validators.controllers.media.not_found'));
```

兩者不要混放。

## 三份 lang 檔必須同步

每次新增訊息 key，**必須同時更新三個檔案**：

```
lang/
  zh-TW/validators.php    ← 繁體中文
  zh-CN/validators.php    ← 簡體中文（用語從中國大陸慣例：字元→字符、陣列→數組、
                             管道→渠道、搜尋→搜索、電子信箱→電子郵箱）
  en/validators.php       ← 英文
```

漏掉任何一份，該語系會直接顯示 key 字串（如 `validators.source.url.required`）給使用者。

檔案結構為巢狀陣列，對應 feature → field → rule：

```php
// lang/zh-TW/validators.php
return [
    'source' => [
        'url' => [
            'required' => '請提供 YouTube 網址。',
            'string'   => '網址格式不正確。',
        ],
    ],
];
```

**不要在 lang 檔用 `*` 當陣列 key**（用欄位名稱取代）。

## 使用 Model const 定義 `in:` 規則

若 Model 已定義對應的 `public const`，`in:` 規則**優先使用 const**，不寫死字串：

```php
use App\Models\Source;

'type' => 'required|string|in:' . implode(',', [
    Source::TYPE_YOUTUBE_CHANNEL,
    Source::TYPE_YOUTUBE_PLAYLIST,
]),
```

若 Model 沒有對應 const（純 API 層概念），才允許直接寫字串。

## 寫規則前先查 DB 欄位長度

**每次撰寫或修改 `set{Action}Rules()`，凡是會被寫進 DB 的字串欄位，都要先確認該欄位的
長度上限，再決定要不要加 `max:`。**

查法：直接看 `database/migrations/` 裡的 `$table->string('欄位', 長度)`。
`string()` 未帶長度時預設 255。

**為什麼一定要查**：測試環境是 **sqlite**（`DB_CONNECTION=sqlite_testing`），
sqlite 對 `varchar(n)` 的長度**完全不強制**，超長字串照樣寫入、測試照樣綠燈；
正式與 staging 是 **PostgreSQL**（Railway 兩個環境的 `DB_CONNECTION` 都是 `pgsql`，
`config/database.php` 的預設值 `mysql` 只是 fallback，不代表實際使用的引擎），
超長字串會直接拋 `QueryException` 變成 500。
**這是測試抓不到的落差**，只能靠寫規則時自己查。

**兩種處置擇一**，判準是「長度是不是使用者該被擋下的錯誤」：

| 情境 | 處置 | 例 |
|------|------|----|
| 使用者自己打的內容 | Validator 加 `max:{欄位長度}`，回 400 | 標題、外部連結、暱稱 |
| 系統／第三方帶進來的附屬資料 | 寫入前 `mb_substr()` 截斷，不擋使用者 | 影片標題、原始檔名 |

```php
// ✅ 使用者輸入：擋下來
'url' => 'required|string|url|max:255',

// ✅ 第三方資料：截斷（用 mb_substr，varchar(n) 算字元數，中文用 substr 會切壞字）
'title' => mb_substr($video['title'], 0, Media::TITLE_MAX_LENGTH),
```

截斷用的長度以 Model 常數表示，不要在多處寫死數字。

## 在 Controller 使用

```php
$validator = (new SourceValidator($request->only(['url', 'notify'])))->setStoreRules();

if (!$validator->passes()) {
    throw new InvalidRequestException($validator->errors()->toArray());
}
```

## 不做的事

- **不傳 locale 參數給 Validator**，語系由 `SetLocale` middleware 統一管理
- **不在 Validator 寫業務邏輯**（查 DB、呼叫 Service）
- **不繼承具體 Validator**（一律直接繼承 `BaseValidator`）
- **不在 `__construct()` 增減參數**（子類別固定 `public function __construct(array $params)`）
- **不把 Validator 綁成 singleton**：`__construct()` 只會執行一次，`__()` 的翻譯會鎖死在
  第一個請求的 locale。Swoole 是常駐程序，這個錯誤在開發環境不會顯現——一律在 Controller `new`
- **不修改 `BaseValidator.php`**（架構層級，需另行確認）

## 測試

Unit Test 放 `tests/Unit/Validators/{Feature}ValidatorTest.php`，
每個 `set{Action}Rules()` 至少測試：必填缺失、合法通過、不合法被拒。
