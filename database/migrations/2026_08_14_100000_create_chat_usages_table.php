<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('chat_usages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUlid('user_id')->comment('使用者 ID');
            $table->date('quota_date')->comment('額度日（依 ai.chat.quota_timezone 計的自然日）');
            $table->unsignedInteger('count')->default(0)->comment('當日已用提問次數');
            $this->timestampsWithIndex($table, false);

            // 扣點是「先鎖住當日這一列再遞增」，唯一索引同時擔任查詢入口與
            // 併發下的最後防線：兩個協程同時建列時只有一個會成功。
            $table->unique(['user_id', 'quota_date']);

            $table->comment('每日 AI 對話用量');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_usages');
    }
};
