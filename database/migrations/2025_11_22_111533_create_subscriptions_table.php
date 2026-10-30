<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Utils\BaseMigration;
use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->index()->comment('使用者 ID');
            $table->foreignUlid('plan_id')->index()->comment('方案 ID');
            $table->foreignUlid('price_id')->index()->comment('價格 ID');
            $table->string('payment_method')->default('paddle')->comment(
                '付款方式'
            );
            $table->timestamp('start_date')->nullable()->index()->comment('開始日');
            $table->timestamp('next_date')->nullable()->index()->comment('下次扣款日');
            $table->timestamp('cancellation_date')->nullable()->index()->comment('取消日期');
            $table->timestamp('last_charged_date')->nullable()->index()->comment('最後付款日');
            $table->string('status')->default(Subscription::STATUS_TRIAL)->index()->comment('狀態');
            $table->text('note')->nullable()->comment('備注');

            $this->timestampsWithIndex($table, false, true);

            $table->comment('訂閱紀錄');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
