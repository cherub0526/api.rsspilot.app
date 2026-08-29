---
paths:
  - "database/migrations/**"
  - "database/seeders/**"
  - "database/factories/**"
---

# Migration 規則

## 核心約束

- **永遠不能修改已存在的 migration** — 已部署的視同歷史紀錄，修改會破壞其他環境的 `migrate`
- 需要改 schema → 建立新的 migration
- 正式環境的 migration 由 Railway 的 `api` service `preDeployCommand` 執行
  （`php artisan migrate --force`），只掛在這一個 service——不要假設 worker 會跑 migration

```bash
docker compose exec hypervel php artisan make:migration create_orders_table
docker compose exec hypervel php artisan make:migration add_status_to_orders_table
```

## 基底類別

建立資料表的 migration 一律 `extends App\Utils\BaseMigration`，
它提供 `timestampsWithIndex()`（建立**帶索引**的 `created_at` / `updated_at`，
並可選擇加上 `softDeletes`）：

```php
protected function timestampsWithIndex(
    Blueprint $table,
    bool $nullable = false,
    bool $withSoftDeletes = false
): void
```

純資料修正（不動 schema）的 migration 可以直接 `extends Hypervel\Database\Migrations\Migration`。

## Import 對照

```php
use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;   // 不是 Illuminate\Support\Facades\Schema
use Hyperf\Database\Schema\Blueprint;  // 不是 Illuminate\Database\Schema\Blueprint
use Hypervel\Support\Facades\DB;       // 資料修正用
```

## 主鍵與外鍵

本專案兩種主鍵並存，**照被關聯的資料表選**：

| 情況 | 寫法 |
|------|------|
| ULID 主鍵的表（`media`、`sources`、`users`…） | `$table->ulid('id')->primary();` |
| 自增主鍵的表 | `$table->bigIncrements('id');` |
| 指向 ULID 表的外鍵 | `$table->foreignUlid('media_id')->index()` |
| 指向自增表的外鍵 | `$table->unsignedBigInteger('plan_id')->index()` |

**不建立 DB 外鍵約束。** 全專案的 migration 沒有任何 `->foreign()`，只有欄位 + `index()`。
關聯完整性由應用層負責——新增 migration 時沿用這個做法，不要單獨引入 FK constraint。

## 欄位型態

| 資料 | 型態 |
|------|------|
| 金額（整數） | `unsignedInteger` |
| 金額（有小數） | `decimal(10, 2)` |
| 狀態欄位 | `string` + Model `public const` |
| 大文字 | `text` |
| JSON | `json` |
| 布林 | `boolean()->default(false)` |
| 計數 | `unsignedInteger()->default(0)` |

## 索引

```php
$table->index('user_id');
$table->index(['user_id', 'created_at']);            // 複合索引
$table->unique(['user_id', 'quota_date']);           // 唯一約束
```

唯一索引若同時擔任**併發下的最後防線**（例如「先查再建」的計數列），
在 migration 裡以 comment 寫明這件事——那是它存在的真正理由，不是順手加的。

## `down()` 必須能還原 `up()`

```php
public function down(): void
{
    Schema::dropIfExists('orders');
}
```

資料修正型的 migration，`down()` 盡力還原即可，但**無法完全還原時要寫明為什麼**。

---

## Comment 規範（必須遵守）

### 規則 1：每個 table 必須有 `$table->comment()`

放在所有欄位定義之後：

```php
$table->comment('用一句中文說明此 table 的業務用途');
```

### 規則 2：每個非標準欄位必須有 `->comment()`

**豁免（不需要 comment）：**
- `$table->ulid('id')->primary()` / `$table->bigIncrements('id')`
- `$this->timestampsWithIndex(...)`（已內建 comment）
- `$table->softDeletes()`
- `$table->rememberToken()`

**其餘所有欄位都必須加 `->comment()`**，包含外鍵 ID、狀態欄位、計數欄位、時間欄位、旗標欄位。

### Comment 撰寫慣例

| 欄位類型 | 寫法 |
|---------|------|
| 外鍵 ID | `'使用者 ID'`、`'媒體 ID'` |
| 狀態欄位 | `'訂閱狀態：ACTIVE / CANCELLED / EXPIRED'` |
| 計數欄位 | `'當日已用提問次數'` |
| 旗標欄位 | `'是否通知：1 開啟 / 0 關閉'` |
| 時間欄位 | `'訂閱到期時間'` |

語言：**繁體中文**，一句話描述業務意義。

### Migration 標準模板

```php
<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('{table_name}', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->string('status', 32)->comment('狀態：ACTIVE / EXPIRED');
            $table->unsignedInteger('count')->default(0)->comment('計數');
            $this->timestampsWithIndex($table, false);

            $table->unique(['user_id', 'status']);

            $table->comment('{table 業務用途說明}');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{table_name}');
    }
};
```

## 字串欄位長度會回到 Validator

新增 `string('欄位', 長度)` 時，**同步確認對應的 Validator 有沒有 `max:`**。
測試環境是 sqlite，不強制長度；正式環境是 MySQL，會拒絕超長寫入。
判準與寫法見 `../validators.md` 的〈寫規則前先查 DB 欄位長度〉。

## Seeder

- 放 `database/seeders/`
- **可重複執行**：以「不存在才建立、已存在不覆寫」的合併邏輯撰寫，不要假設是空庫
- 若同一份資料同時需要由 migration 與 seeder 建立，**兩者共用同一支合併邏輯**，
  不要各寫一份（見 `OpenRouterModelsSeeder` 與其對應的 migration）
