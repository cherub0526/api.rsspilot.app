<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    /**
     * 讓一則訊息能承載多個「片段」，為 agent 能力（思考過程、工具呼叫）預留結構。
     *
     * 為什麼是 JSON 欄位而不是另開一張 parts 表：片段永遠是整則一起讀、一起寫，
     * 順序就是陣列順序，沒有單獨查詢某個片段的需求。多一張表只換來 join 與排序欄位。
     *
     * 可為 null 是刻意的——既有資料列不做回填，讀取時由 ChatMessage::contentParts()
     * 退回 content 組成的單一 text 片段。回填要嘛在 migration 裡逐列 UPDATE（量大時很慢），
     * 要嘛用不可攜的 JSON 函式；而 null 這條路兩者都不必，語意也更誠實：
     * 「這列是在有 parts 之前寫的」。
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->json('parts')->nullable()->after('content')->comment('訊息片段（text / thinking / tool_call / tool_result）');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('parts');
        });
    }
};
