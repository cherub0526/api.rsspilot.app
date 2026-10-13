<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

/*
 * 開一個「首期優惠價」欄位，給年繳的首年折價用。
 *
 * `first_period_price` 只填**首期**的價碼，`price` 仍然是續約價。null 代表這個
 * price 沒有首期優惠。
 *
 * 這支 migration 只開欄位、**不填任何金額**，而且會一直是這樣：owner 在
 * 2026-10-13 決定兩個方案都不降價（新客優惠統一成 `Subscription::TRIAL_DAYS`
 * 天的免費試用，月繳與年繳都適用）。全部 null 就是「不降價」，所以這裡沒有
 * 需要補的值。
 *
 * 機制留著是因為它不需要任何決定就能運作：日後真要折價，填這個欄位就會讓那個
 * price 從試用那條路切到折扣碼那條路（見
 * `CreemSubscriptionService::grantsTrial()`），兩種優惠互斥。真的要填值時請**用
 * 一支新的 migration**，不要只改 seeder——`docs/pricing-cost-model.md` §6 記著
 * 這個 drift 已經發生三次（seeder 改了、正式環境沒改，整張毛利表因此不成立）。
 */
return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->decimal('first_period_price', 8, 2)
                ->nullable()
                ->after('price')
                ->comment('首期優惠價（年繳首年），null = 無優惠，續約一律收 price');
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn('first_period_price');
        });
    }
};
