---
name: hypervel-validator
description: 在此 Hypervel 專案中新增或修改 Validator 時使用。涵蓋 BaseValidator instance-based 模式、set*Rules() 撰寫、Controller 呼叫方式、自訂錯誤訊息、測試撰寫。不處理路由設計或 Service 層商業邏輯。
version: 1.0.0
metadata: {"author": "Ethan"}
---

# Hypervel Validator

此 skill 規範專案中所有輸入驗證的撰寫方式。驗證器採用 **instance-based 模式**，由 `App\Validators\BaseValidator` 提供基礎介面；各 domain 子類別定義 `set*Rules()` 方法，Controller 在每次請求時建立新 instance 並呼叫對應的 rules 方法。

此 skill 不處理路由設計（URL 命名、版本策略）、Service 層商業邏輯，或 HTTP 回應格式規範。

## 核心模式

```
new {Domain}Validator($data)   →  set{Scene}Rules()  →  if (!$v->passes()) throw InvalidRequestException
```

`BaseValidator` 公開的方法：

| 方法 | 說明 |
|------|------|
| `passes(): bool` | 執行驗證並回傳是否通過（同時初始化 validator） |
| `errors(): MessageBag` | 驗證錯誤訊息集合（需先呼叫 `passes()`） |
| `getRules(): array` | 取得目前設定的驗證規則 |
| `getMessages(): array` | 取得目前設定的自訂訊息 |

驗證失敗時拋出 `App\Exceptions\InvalidRequestException`，傳入 `$v->errors()->toArray()`。

## 目錄結構

```
app/Validators/
├── BaseValidator.php          ← 基礎類別（禁止直接修改）
├── AuthValidator.php          ← 範例：Auth domain
└── {Domain}Validator.php      ← 新 domain 按此命名
```

<role>
Act as a backend engineer who knows this project's Hypervel 3.1 validation layer, the BaseValidator instance-based contract, and PSR-12 code style. Prefer minimal, direct implementations — no abstraction beyond what the task requires.
</role>

<decision_boundary>
Use when:
- 新增 Validator 類別（`app/Validators/{Domain}Validator.php`）
- 在 `set*Rules()` 中新增或修改驗證規則
- 在 Controller 中串接驗證器（`new FooValidator($data)` → `setBarRules()` → `if (!$v->passes()) throw`）
- 新增自訂錯誤訊息（在 constructor 設定 `$this->messages`）
- 為 Validator 撰寫測試（`tests/Unit/Validators/`）

Do not use when:
- 討論 API 路由設計或 URL 命名
- 修改 `BaseValidator.php` 本身（架構層級，需另行確認）
- 在 Service 層做驗證（Service 不依賴 HTTP 層）
- 處理 Model 層的 database constraint 錯誤

Inputs:
- 需驗證的 Controller method 與 `$request->all()` 結構
- 欄位名稱與對應的 Hypervel/Laravel validation rules
- 是否需要自訂中文錯誤訊息

Successful output:
- 正確繼承 `BaseValidator` 的子類別（`set*Rules()` 呼叫 `$this->make()`）
- Controller 使用 `new {Validator}($data); set*Rules(); throwIfFails(); validated()` 模式
- 對應的 PHPUnit 測試通過
</decision_boundary>

## Primary use cases

1) **建立新 Validator 類別**
   - Trigger：「新增 CourseValidator」「幫 `store()` 寫 validation」「這個 controller 需要驗證」
   - Inputs：domain 名稱、欄位清單與 rules
   - Expected result：`app/Validator/V1/{Domain}/{Domain}Validator.php`，至少一個 `set*Rules()` 方法

2) **在 Controller 中串接 Validator**
   - Trigger：「在 controller 加驗證」「把 validation 加進去」
   - Inputs：controller method 、validator 類別與對應的 rules method 名稱
   - Expected result：`new {Validator}($this->request->all()); set*Rules(); throwIfFails(); $data = $v->validated();`

3) **新增 rules method**
   - Trigger：「新增 update 的驗證規則」「幫 `setUpdateRules` 加欄位」
   - Inputs：目標 Validator 類別、新欄位與 rules
   - Expected result：新的 `set{Scene}Rules(): void` 方法，內部呼叫 `$this->make([...])`

4) **撰寫 Validator 測試**
   - Trigger：「幫 CourseValidator 寫測試」「為這個 validator 加測試」
   - Inputs：Validator 類別與 rules methods
   - Expected result：`tests/Unit/Validators/{Domain}ValidatorTest.php`，涵蓋 happy-path 與必填欄位缺失

<workflow>
Step 0: Orient
- Action：確認 domain 名稱（e.g., `Course`）、目標 Controller method、需驗證的欄位
- Validation：`app/Validators/{Domain}Validator.php` 是否存在；若不存在則建立

Step 1: 建立或定位 Validator 類別
- Action：若 `{Domain}Validator.php` 不存在 → 建立，加上 namespace、繼承 `BaseValidator`、在 constructor 初始化 `$this->messages`
- Validation：namespace 為 `App\Validators`；類別名稱為 `{Domain}Validator`；繼承 `App\Validators\BaseValidator`

Step 2: 撰寫 set*Rules() 方法
- Action：在 Validator 類別中加入 `set{Scene}Rules(): self`，內部設定 `$this->rules = [...]` 並 `return $this`
- Rules 命名規則：`set` + 動詞/名詞 + `Rules`（e.g., `setStoreRules`、`setUpdateRules`、`setRegisterRules`）
- 回傳型別固定為 `self`（支援 fluent chaining）
- Validation：每個 `set*Rules()` 必須設定 `$this->rules`；禁止 return array

Step 3: 新增自訂訊息（視需求）
- Action：若需要中文錯誤訊息，在 constructor 中設定 `$this->messages = ['field.rule' => '訊息', ...]`
- Key 格式：`'{field}.{rule}'`（e.g., `'email.required' => __('validators.auth.email.required')`）
- Validation：key 格式正確；優先使用 `__()` 語系函數對應 `lang/zh_TW/validators.php`

Step 4: 在 Controller 串接
- Action：在 controller method 中加入以下模式：
  ```php
  $v = new {Domain}Validator($request->only([...]));
  $v->set{Scene}Rules();

  if (! $v->passes()) {
      throw new InvalidRequestException($v->errors()->toArray());
  }
  ```
- Validation：不注入 Validator 為 singleton；每次請求建立新 instance；`use` import 已加入（`App\Validators\{Domain}Validator`、`App\Exceptions\InvalidRequestException`）

Step 5: 撰寫測試（若需要）
- Action：在 `tests/Unit/Validators/{Domain}ValidatorTest.php` 建立測試類別
- 最少涵蓋：passes when valid、fails when required field missing、errors() contains correct key
- Validation：測試類別繼承 `Tests\TestCase`；測試方法命名用 `test_` 前綴 snake_case
</workflow>

<output_contract>
輸出順序：
1. Validator 類別完整內容（fenced PHP）
2. Controller 中的串接程式碼片段（fenced PHP，只含變動段落）
3. 測試類別完整內容（fenced PHP，若任務包含測試）
4. 一句話說明 rules method 命名選擇（若非顯而易見）

不輸出：
- `BaseValidator.php` 本身（禁止修改）
- 路由或 Service 層的建議
- 多個替代方案（除非 user 索取）
</output_contract>

<tool_rules>
- Read：動筆前先讀目標 controller 與現有 validator（若存在）
- Glob：確認 `app/Validator/V1/{Domain}/` 是否存在
- Write：新建 Validator 或測試檔案
- Edit：修改既有 Controller 串接段落
- 不直接修改 `BaseValidator.php`
- 不執行測試（交給 user 確認）
</tool_rules>

<default_follow_through_policy>
- **直接做**：建立 Validator 類別、撰寫 rules method、更新 controller、建立測試
- **先問**：rules 定義不明確（哪些欄位必填、型別限制為何）
- **停下回報**：發現 Service 層或 Model 層有同名驗證邏輯，需確認是否重複
</default_follow_through_policy>

<examples>

### 範例 1 — 建立新 Validator 類別

任務：為 Course 建立含 store / update 兩個 scene 的 validator

```php
<?php

declare(strict_types=1);

namespace App\Validators;

class CourseValidator extends BaseValidator
{
    public function __construct(array $params)
    {
        parent::__construct($params);

        $this->messages = [
            'title.required' => '請輸入課程標題',
            'price.required' => '請輸入課程價格',
            'price.min'      => '價格不能為負數',
        ];
    }

    public function setStoreRules(): self
    {
        $this->rules = [
            'title'       => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'price'       => 'required|integer|min:0',
        ];

        return $this;
    }

    public function setUpdateRules(): self
    {
        $this->rules = [
            'title'       => 'sometimes|string|max:191',
            'description' => 'nullable|string|max:2000',
            'price'       => 'sometimes|integer|min:0',
        ];

        return $this;
    }
}
```

---

### 範例 2 — Controller 串接

```php
use App\Validators\CourseValidator;
use App\Exceptions\InvalidRequestException;
use Psr\Http\Message\ResponseInterface;

public function store(Request $request): ResponseInterface
{
    $v = new CourseValidator($request->only(['title', 'description', 'price']));
    $v->setStoreRules();

    if (! $v->passes()) {
        throw new InvalidRequestException($v->errors()->toArray());
    }

    $course = $this->courseService->create($request->only(['title', 'description', 'price']));

    return response()->json(CourseResource::make($course), 201);
}

public function update(Request $request, int $id): ResponseInterface
{
    $v = new CourseValidator($request->only(['title', 'description', 'price']));
    $v->setUpdateRules();

    if (! $v->passes()) {
        throw new InvalidRequestException($v->errors()->toArray());
    }

    $course = $this->courseService->update($id, $request->only(['title', 'description', 'price']));

    return response()->json(CourseResource::make($course));
}
```

> 同一個 `CourseValidator` 被兩個 route 複用 — 這正是 instance-based 模式的優勢。

---

### 範例 3 — Validator 測試

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use App\Validators\CourseValidator;
use Tests\TestCase;

class CourseValidatorTest extends TestCase
{
    public function test_store_passes_with_valid_data(): void
    {
        $v = new CourseValidator(['title' => 'PHP 入門', 'price' => 0]);
        $v->setStoreRules();

        $this->assertTrue($v->passes());
    }

    public function test_store_fails_when_title_missing(): void
    {
        $v = new CourseValidator(['price' => 100]);
        $v->setStoreRules();

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('title', $v->errors()->toArray());
    }

    public function test_store_fails_when_price_missing(): void
    {
        $v = new CourseValidator(['title' => 'PHP 入門']);
        $v->setStoreRules();

        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('price', $v->errors()->toArray());
    }

    public function test_update_passes_with_partial_data(): void
    {
        $v = new CourseValidator(['title' => '進階 PHP']);
        $v->setUpdateRules();

        $this->assertTrue($v->passes());
    }
}
```

</examples>

## References

- `references/rules-cheatsheet.md`：常用 Hypervel/Laravel validation rules 速查
- `references/naming-guide.md`：set*Rules() 命名規範與 domain 分層原則
