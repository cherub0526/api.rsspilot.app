<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

return new class extends BaseMigration {
    /**
     * `plans.chat_limit` 從建表起就存在，但一直沒有任何程式讀它，seeder 也沒設值，
     * 所以正式環境的三個方案全是 0。「每日提問上限」上線後 0 代表不限制，若不補值
     * 等於整個功能沒有生效，因此這裡把既有方案補上預設額度。
     *
     * 只更新仍是 0 的資料列：已經有人手動調過的方案不該被蓋掉。
     */
    private const DAILY_LIMITS = [
        'Free'    => 3,
        'Pro'     => 50,
        'Advance' => 200,
    ];

    public function up(): void
    {
        foreach (self::DAILY_LIMITS as $title => $limit) {
            DB::table('plans')
                ->where('title', $title)
                ->where('chat_limit', 0)
                ->update(['chat_limit' => $limit]);
        }

        // 欄位註解原本寫「聊天次數限制」，沒有講清楚週期。sqlite 沒有欄位註解。
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `plans` MODIFY `chat_limit` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每日 AI 提問次數上限，0表示不限制'"
            );
        }
    }

    public function down(): void
    {
        DB::table('plans')
            ->whereIn('title', array_keys(self::DAILY_LIMITS))
            ->whereIn('chat_limit', array_values(self::DAILY_LIMITS))
            ->update(['chat_limit' => 0]);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `plans` MODIFY `chat_limit` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '聊天次數限制，0表示不限制'"
            );
        }
    }
};
