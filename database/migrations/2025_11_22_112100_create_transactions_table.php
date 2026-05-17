<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')->index()->comment('訂閱 ID');
            $table->timestamp('billing_date')->index()->comment('扣款日期');
            $table->decimal('amount', 8, 2)->default(0.00)->comment('扣款金額');
            $table->string('status')->nullable()->index()->comment('付款狀態');
            $this->timestampsWithIndex($table, false, true);

            $table->comment('交易紀錄');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
