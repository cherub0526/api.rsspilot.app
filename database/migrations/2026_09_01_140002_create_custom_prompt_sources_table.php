<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

/*
 * 設定套用到哪些頻道／清單。
 *
 * 為什麼是獨立的關聯表而不是把 custom_prompt_id 加在 sources 上：sources 是跨
 * 使用者共用的（一個 YouTube 頻道一列，user_sources 才是每個人的訂閱），加在
 * 那裡會讓一個人的設定套用到所有訂閱同一頻道的人身上。
 *
 * unique 擋重複掛載；「這個 source 是不是該使用者訂閱的」由應用層驗證——
 * 本專案不建 DB 外鍵約束，這張表也管不到 user_id。
 */
return new class extends BaseMigration {
    public function up(): void
    {
        Schema::create('custom_prompt_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('custom_prompt_id')->index()->comment('自訂提示 ID');
            $table->foreignUlid('source_id')->index()->comment('來源 ID');
            $this->timestampsWithIndex($table, false, false);

            $table->unique(['custom_prompt_id', 'source_id']);
            $table->comment('自訂提示套用的來源');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_prompt_sources');
    }
};
