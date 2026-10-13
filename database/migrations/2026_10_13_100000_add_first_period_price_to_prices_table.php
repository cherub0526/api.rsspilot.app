<?php

declare(strict_types=1);

use App\Models\Price;
use App\Utils\BaseMigration;
use Hypervel\Support\Facades\DB;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

/*
 * 年繳的新客優惠從「首月免費」改成「首年折價」。
 *
 * 首月免費把年繳的第一筆扣款整整推後 30 天——對 2026-12-08 的淨入帳目標來說，
 * 30 天內成交的客人在期限前一毛都不會入帳。折價是當天就收到錢，客人也省得更多。
 *
 * `first_period_price` 只填**首期**的價碼，`price` 仍然是續約價。null 代表這個
 * price 沒有首期優惠（月繳一律 null，它維持首月免費）。
 *
 * 金額要由 migration 管，不能只改 seeder：`docs/pricing-cost-model.md` §6 記著
 * 這個 drift 已經發生三次（seeder 改了、正式環境沒改，整張毛利表因此不成立）。
 */
return new class extends BaseMigration {
    /** Pro 年繳首年價。毛利驗算見 docs/pricing-cost-model.mjs 的「首年折價」一節。 */
    private const float PRO_FIRST_YEAR = 99.00;

    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->decimal('first_period_price', 8, 2)
                ->nullable()
                ->after('price')
                ->comment('首期優惠價（年繳首年），null = 無優惠，續約一律收 price');
        });

        // Pro 年繳 $129 → 首年 $99。用 price 比對而不是 plan 名稱：方案可以改名，
        // 但「現在掛著 129 的那筆年繳」是唯一要動的對象。
        //
        // Advance 年繳刻意**不填**。首年 $189 在 Advance 的典型情境（$15.52/月成本）
        // 毛利是 −3%，不是極端使用者小虧而是平均每位都虧。要折價得先動
        // docs/pricing-cost-model.md §5 那三個旋鈕，待 CEO 決定。
        DB::table('prices')
            ->where('unit', Price::UNIT_ANNUALLY)
            ->where('price', 129.00)
            ->whereNull('first_period_price')
            ->update(['first_period_price' => self::PRO_FIRST_YEAR]);
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn('first_period_price');
        });
    }
};
