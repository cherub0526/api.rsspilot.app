<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('creems', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('foreign_id')->index()->comment('外鍵 ID');
            $table->string('foreign_type')->index()->comment('外鍵 Type');
            $table->string('creem_id')->index()->nullable()->comment('Creem ID');
            $table->text('creem_detail')->nullable()->comment('Creem Detail');

            // Creem 的價格綁在 product 上，試用長度也是（trial_period_days），而且
            // 結帳時無法覆寫。「首月免費只送第一次」這條規則因此只能靠「同一個
            // price 在 Creem 上建兩個 product」來守：一個含試用、一個不含，結帳時
            // 依資格挑。這個欄位就是用來分辨那兩列的。
            //
            // Paddle 不需要這一欄，因為它有 activate 端點可以當場結束試用；
            // Stripe 也不需要，因為 trial_end 是結帳當下傳的。
            $table->string('variant')->default('standard')->index()->comment('product 變體：standard / trial');

            $table->index(['foreign_id', 'foreign_type']);
            $table->index(['foreign_id', 'foreign_type', 'variant'], 'creems_foreign_variant_index');

            $this->timestampsWithIndex($table, false, false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creems');
    }
};
