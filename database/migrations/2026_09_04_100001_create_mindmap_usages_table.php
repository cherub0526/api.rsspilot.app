<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('mindmap_usages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUlid('user_id')->comment('使用者 ID');
            $table->date('quota_date')->comment('額度日（依 ai.chat.quota_timezone 計的自然日）');
            $table->unsignedInteger('count')->default(0)->comment('當日已用心智圖產生次數');
            $this->timestampsWithIndex($table, false);

            // 與 chat_usages 同形：扣點是「先鎖住當日這一列再遞增」，唯一索引同時
            // 擔任查詢入口與併發下的最後防線。見 DailyQuotaService。
            $table->unique(['user_id', 'quota_date']);

            $table->comment('每日心智圖用量');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mindmap_usages');
    }
};
