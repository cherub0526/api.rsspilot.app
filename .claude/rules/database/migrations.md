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

## 主鍵一律用 ULID

**新建的資料表，主鍵一律是 ULID，沒有例外：**

```php
$table->ulid('id')->primary();
```

**不要用** `bigIncrements()`、`increments()`、`id()`。

對應的 Model 必須 `use HasUlids`，否則寫入時不會自動產生 id、直接違反 NOT NULL：

```php
use Hypervel\Database\Eloquent\Concerns\HasUlids;

class Order extends Model
{
    use HasUlids;
}
```

### 為什麼

- ULID 在 client 端就能產生，不必等資料庫回傳，也不必為了拿 id 而先寫入
- 主鍵不洩漏「總共有幾筆」與「建立順序的間距」，這兩件事在對外 API 上是資訊洩漏
- 本專案的 id 會直接出現在 URL（`/v1/media/{mediaId}`）與 API 回應中，自增值可被枚舉
- ULID 內含時間戳，仍然可依主鍵排序，不會失去自增值唯一的好處

### 連帶的三件事

| 面向 | 做法 | 規則 |
|------|------|------|
| 外鍵 | `$table->foreignUlid('media_id')->index()` | 本檔下一節 |
| 路由參數 | `'/{mediaId:[0-7][0-9a-hjkmnp-tv-z]{25}}'` | `../routes.md` |
| Resource 輸出 | `strval($this->resource->id)`，**不是** `intval()` | `../resources.md` |

### 既有的自增主鍵是歷史包袱，不要回頭改

這 7 張表的主鍵是 `bigIncrements`：

```
oauths  paddles  stripes  settings  configs  chat_usages  jobs
```

（`jobs` 是框架的佇列表，不歸我們管。）

**不要為了一致性去改它們。** 改主鍵型別要重建整張表、轉換既有資料、同步所有指向它的
外鍵欄位，而這些表都已經部署在正式環境。除非有獨立的理由，維持現狀。

指向這些表的外鍵沿用 `$table->unsignedBigInteger('plan_id')->index()`——**照被關聯的
那張表選**，不是照這條規則選。

## 不建立 DB 外鍵約束

全專案的 migration 沒有任何 `->foreign()`，只有欄位 + `index()`。
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
- `$table->ulid('id')->primary()`（既有表的 `bigIncrements('id')` 同樣豁免）
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
            $table->ulid('id')->primary();
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
測試環境是 sqlite，不強制長度；正式與 staging 是 PostgreSQL，會拒絕超長寫入。
判準與寫法見 `../validators.md` 的〈寫規則前先查 DB 欄位長度〉。

## 改既有欄位的型別要手寫 SQL

Blueprint 的 `->change()` **在這個專案不能用**——它會走
`Hyperf\Database\Schema\Grammars\ChangeColumn::compile()`，那裡先檢查
`isDoctrineAvailable()`，而 `doctrine/dbal` 沒有安裝，直接拋 `RuntimeException`。

改用 `DB::statement()` 手寫，並依 driver 分支——改欄位型別的語法三種資料庫互不相容：

```php
match (DB::connection()->getDriverName()) {
    'pgsql' => DB::statement('ALTER TABLE t ALTER COLUMN c TYPE TEXT'),
    'mysql' => DB::statement("ALTER TABLE `t` MODIFY `c` TEXT NOT NULL COMMENT '說明'"),
    default => null,   // sqlite：測試環境，沒有改型別的語法，也不強制長度
};
```

兩個容易漏的細節：

- **MySQL 的 `MODIFY` 是整欄重新定義**，沒帶到的 `COMMENT`、`NOT NULL` 會被清掉，必須重寫。
- **PostgreSQL 的欄位註解存在 `pg_description`**，改型別不會動到它，**不要**重下 `COMMENT`。

實例見 `2026_08_30_100000_change_oauths_tokens_to_text.php`。

## Seeder

- 放 `database/seeders/`
- **可重複執行**：以「不存在才建立、已存在不覆寫」的合併邏輯撰寫，不要假設是空庫
- 若同一份資料同時需要由 migration 與 seeder 建立，**兩者共用同一支合併邏輯**，
  不要各寫一份（見 `OpenRouterModelsSeeder` 與其對應的 migration）
