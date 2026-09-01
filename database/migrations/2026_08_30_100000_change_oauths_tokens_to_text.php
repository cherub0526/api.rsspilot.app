<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * `oauths.token` 與 `oauths.refresh_token` 由 varchar(1024) 改為 TEXT。
     *
     * provider 的憑證是原封不動寫進來的（見 SocialAccountService::storeCredentials()），
     * 沒有截斷也不該截斷——被切一半的憑證是壞掉的憑證，寫進去比寫不進去更糟，而且要等到
     * 日後拿它去呼叫 provider API 才會發現。所以正確的處置是把欄位放寬，不是加 max。
     *
     * 1024 目前對 Google 夠用（access token 約 100–300 字元、refresh token 約 100），
     * 但長度是 provider 說了算，scope 變多或換成會回 ID token 的流程都可能撐爆，
     * 屆時資料庫直接拒收 → 500。
     *
     * 為什麼是手寫 SQL 而不是 Blueprint 的 ->change()：那條路徑要 doctrine/dbal
     * （見 Hyperf\Database\Schema\Grammars\ChangeColumn::compile() 的 isDoctrineAvailable
     * 檢查），本專案沒有裝，為了兩個欄位引進一整套 schema 差異引擎不划算。
     *
     * 三種 driver 分開處理，因為改欄位型別的語法彼此不相容：
     *   - pgsql：正式與 staging 用的就是這個（Railway 兩個環境的 DB_CONNECTION 都是 pgsql，
     *     config/database.php 的預設值 mysql 只是 fallback）。varchar(n) → text 在
     *     PostgreSQL 是 binary-coercible，不會重寫整張表；欄位註解存在 pg_description，
     *     改型別不會動到它，所以不必重下 COMMENT。
     *   - mysql：本機或日後換回 MySQL 時才會走到。MODIFY COLUMN 是整欄重新定義，沒帶到的
     *     屬性會被清掉，所以 COMMENT 與可否為 NULL 都必須重寫——兩欄在這一點上不同，
     *     token 是 NOT NULL，refresh_token 是 NULL。
     *   - sqlite（測試）：不強制 varchar 長度，本來就沒有這個問題；它也沒有修改欄位型別的
     *     語法，硬跑會失敗。直接跳過。
     */
    public function up(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->runStatements([
                'ALTER TABLE oauths ALTER COLUMN token TYPE TEXT',
                'ALTER TABLE oauths ALTER COLUMN refresh_token TYPE TEXT',
            ]),
            'mysql' => $this->runStatements([
                "ALTER TABLE `oauths` MODIFY `token` TEXT NOT NULL COMMENT '存取憑證'",
                "ALTER TABLE `oauths` MODIFY `refresh_token` TEXT NULL COMMENT '重新整理憑證'",
            ]),
            default => null,
        };
    }

    /**
     * 還原成原本的寬度。
     *
     * 若此時已存在超過 1024 字元的憑證，資料庫會拒絕這次還原——那是對的，
     * 資料不該為了回滾而被默默截掉。
     */
    public function down(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => $this->runStatements([
                'ALTER TABLE oauths ALTER COLUMN token TYPE VARCHAR(1024)',
                'ALTER TABLE oauths ALTER COLUMN refresh_token TYPE VARCHAR(1024)',
            ]),
            'mysql' => $this->runStatements([
                "ALTER TABLE `oauths` MODIFY `token` VARCHAR(1024) NOT NULL COMMENT '存取憑證'",
                "ALTER TABLE `oauths` MODIFY `refresh_token` VARCHAR(1024) NULL COMMENT '重新整理憑證'",
            ]),
            default => null,
        };
    }

    /**
     * @param array<int, string> $statements
     */
    private function runStatements(array $statements): void
    {
        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }
};
