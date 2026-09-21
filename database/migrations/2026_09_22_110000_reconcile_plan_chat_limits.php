<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 把兩個付費方案的每日提問額度對齊 seeder 的意圖：Pro 20、Advance 30。
     *
     * **這一支要處理的是一段 drift，不只是一次調降。** 2026-08 決定把額度降下來
     * 時只改了 `PlanPriceSeeder`，而 seeder 只在建表時跑一次，正式與 staging 的
     * 資料列是當初就建好的，不會因為改 seeder 而改變。結果是：
     *
     * - `2026_08_14_100001` 是唯一真的寫進資料庫的一支，它設的是 Pro 50、
     *   Advance 200。
     * - seeder 後來改成 Pro 30 → 20、Advance 50，都沒有對應的 migration。
     * - `2026_09_01_110000`（Pro 30 → 20）的條件是 `where chat_limit = 30`，
     *   如果正式環境還停在 50，那一支是完全 no-op 的。
     *
     * 所以這裡用 `whereIn` 收掉每一個「曾經是官方值」的舊值，而不是只認一個：
     *
     * | 方案 | 可能的現值 | 來源 | 目標 |
     * |---|---|---|---|
     * | Pro | 50 | `2026_08_14_100001` | 20 |
     * | Pro | 30 | seeder 的中間值 | 20 |
     * | Advance | 200 | `2026_08_14_100001` | 30 |
     * | Advance | 50 | seeder 的前一個值 | 30 |
     *
     * 值不在清單裡的資料列一律不動——那代表有人針對那一列手動調過，不該被這支
     * 蓋掉。同一個理由讓這支是冪等的：跑第二次時值已經是目標值，不在 `whereIn`
     * 裡，什麼都不會發生。
     *
     * 調降的依據是「成本天花板要壓在售價的 1.5 倍以內」，Advance 在 50 題/天時
     * 是 1.87x。完整試算見 rsspilot.app repo 的 docs/pricing-cost-model.md。
     */
    private const LIMITS = [
        'Pro'     => ['from' => [50, 30], 'to' => 20],
        'Advance' => ['from' => [200, 50], 'to' => 30],
    ];

    public function up(): void
    {
        foreach (self::LIMITS as $title => $limit) {
            DB::table('plans')
                ->where('title', $title)
                ->whereIn('chat_limit', $limit['from'])
                ->update(['chat_limit' => $limit['to']]);
        }
    }

    /**
     * 退回 `2026_08_14_100001` 設的值，不是退回 seeder 那些從未進過資料庫的中間值
     * ——那才是這些資料列真正走過的上一個狀態。
     */
    public function down(): void
    {
        foreach (self::LIMITS as $title => $limit) {
            DB::table('plans')
                ->where('title', $title)
                ->where('chat_limit', $limit['to'])
                ->update(['chat_limit' => $limit['from'][0]]);
        }
    }
};
