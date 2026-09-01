<?php

declare(strict_types=1);

use Hypervel\Support\Str;
use Carbon\CarbonImmutable;
use App\Utils\BaseMigration;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

/*
 * custom_prompts 的主鍵由自增整數改為 ULID。
 *
 * 為什麼需要這一支：建表 migration（2026_01_21_161034）是被就地改成 ulid() 的，
 * 那只對全新安裝生效——已經跑過它的環境（正式、staging）資料表仍是 bigint，
 * 而 model 已經 use HasUlids、路由約束也已經是 ULID pattern，兩邊對不上。
 *
 * 沒有任何資料表以外鍵指向 custom_prompts.id（本專案不建 FK 約束，也沒有
 * custom_prompt_id 欄位），所以不必處理級聯。
 */
return new class extends BaseMigration {
    private const TABLE = 'custom_prompts';

    public function up(): void
    {
        if (!$this->needsConversion()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->char('id_ulid', 26)->nullable()->comment('轉換中的新主鍵');
        });

        $this->fillUlids();

        $this->swapPrimaryKey('id', 'id_ulid');
    }

    /**
     * 反向轉換會重新產生整數 id，**不是**還原成原本那組值——原值在 up() 已經丟掉，
     * 沒有地方可以取回。因為沒有外鍵指向這張表，重新編號不會破壞關聯，但任何在外部
     * 記下舊 id 的東西（書籤、報表）都會對不上。
     */
    public function down(): void
    {
        if (!$this->isUlidColumn()) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ' . self::TABLE . ' DROP CONSTRAINT ' . self::TABLE . '_pkey');
        DB::statement('ALTER TABLE ' . self::TABLE . ' DROP COLUMN id');
        DB::statement('ALTER TABLE ' . self::TABLE . ' ADD COLUMN id bigserial');
        DB::statement('ALTER TABLE ' . self::TABLE . ' ADD CONSTRAINT ' . self::TABLE . '_pkey PRIMARY KEY (id)');
    }

    /**
     * 只有「Postgres 且 id 仍是整數」需要轉換。
     *
     * 全新安裝的建表 migration 已經給了 char(26)，重跑這支等於沒事可做；sqlite 在
     * 這裡直接跳過——它的型別親和性讓 ULID 字串本來就寫得進 INTEGER 欄位，測試環境
     * 每次都是全新建表，沒有舊資料要救。
     *
     * 其他 driver 若真的還是整數主鍵，寧可大聲停下來：靜默跳過會留下 model 產 ULID、
     * 欄位卻是整數的壞組合，而那是執行期才會炸的問題。
     */
    private function needsConversion(): bool
    {
        if ($this->isUlidColumn()) {
            return false;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return false;
        }

        if ($driver !== 'pgsql') {
            throw new \RuntimeException(
                "custom_prompts.id 仍是整數，但這支 migration 只實作了 Postgres 的轉換（目前 driver：{$driver}）。"
            );
        }

        return true;
    }

    private function isUlidColumn(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            // 非 Postgres 一律交給 needsConversion() 依 driver 決定，這裡不臆測型別。
            return false;
        }

        $column = DB::selectOne(
            'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [self::TABLE, 'id']
        );

        return $column !== null && str_starts_with((string) $column->data_type, 'char');
    }

    /**
     * 逐列產生 ULID。
     *
     * 用 PHP 的 Str::ulid() 而不是在 SQL 裡自己組 base32——model 的 HasUlids 走的就是
     * 這一支，格式（含小寫）保證一致，不會出現「migration 產的 id 跟程式產的長得不一樣」。
     *
     * 時間戳取 created_at 而不是 now()：ULID 的前 10 碼是毫秒時間戳，
     * CustomPromptsController::index 又是 orderByDesc('id')，用建立時間產生才能讓既有
     * 資料的排序維持原本的先後。
     */
    private function fillUlids(): void
    {
        DB::table(self::TABLE)
            ->orderBy('id')
            ->select(['id', 'created_at'])
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $createdAt = $row->created_at === null
                        ? CarbonImmutable::now()
                        : CarbonImmutable::parse($row->created_at);

                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update(['id_ulid' => strtolower((string) Str::ulid($createdAt))]);
                }
            });
    }

    /**
     * 丟掉舊主鍵、把新欄位接上去。
     *
     * 主鍵約束最後才加：真的撞到重複值時它會失敗，整支 migration 連同前面的 UPDATE
     * 一起回滾，不會留下半套資料。
     */
    private function swapPrimaryKey(string $old, string $new): void
    {
        $table = self::TABLE;

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_pkey");
        DB::statement("ALTER TABLE {$table} DROP COLUMN {$old}");
        DB::statement("ALTER TABLE {$table} RENAME COLUMN {$new} TO {$old}");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$old} SET NOT NULL");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_pkey PRIMARY KEY ({$old})");
    }
};
