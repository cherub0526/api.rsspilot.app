<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('clinks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('foreign_id')->index()->comment('外鍵 ID');
            $table->string('foreign_type')->index()->comment('外鍵 Type：Plan / Price / Subscription');
            $table->string('clink_id')->nullable()->index()->comment('Clink ID：prd_ / price_ / sess_ → sub_');
            $table->text('clink_detail')->nullable()->comment('Clink 回傳的原始物件（JSON）');

            // Clink 的試用期綁在 price 的 trialPeriodDays 上，結帳時不能覆寫，所以
            // 每個付費 price 在 Clink 建兩個 price（含試用 / 不含），結帳時依資格挑。
            $table->string('variant')->default('standard')->index()->comment('price 變體：standard / trial');

            $this->timestampsWithIndex($table, false, false);

            $table->index(['foreign_id', 'foreign_type']);
            $table->index(['foreign_id', 'foreign_type', 'variant'], 'clinks_foreign_variant_index');

            // 併發下的最後防線：同一則事件重送、webhook 與回站 confirm 同時到時，
            // 同一個 Clink id 在同一種對象上只會有一列。clink_id 為 NULL 的列不受限。
            $table->unique(['foreign_type', 'clink_id']);

            $table->comment('Clink（Merchant of Record）與我方 Plan / Price / Subscription 的對應');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinks');
    }
};
