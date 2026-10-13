<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
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
 * 這支 migration 只開欄位、**不填任何金額**。折價數字還沒拍板（2026-10-13），
 * 而全部 null 的行為是安全的那一邊：年繳照原價收，但已經不送免費月，現金仍然
 * 提前 30 天。拍板後用另一支 migration 填值——金額不能只改 seeder，
 * `docs/pricing-cost-model.md` §6 記著這個 drift 已經發生三次（seeder 改了、
 * 正式環境沒改，整張毛利表因此不成立）。
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
