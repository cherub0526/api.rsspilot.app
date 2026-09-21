<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 把 RSSPilot 接到自己的 AI 工具（`/mcp`）變成一個方案權益。
     *
     * 判準用欄位而不是方案名稱，與 `download_enabled` 一類的旗標同一套做法：
     * 權益寫在資料上，日後調整方案分級不必回頭改程式。
     *
     * Pro 以上開放。預設 false——無從判斷權益時（沒有方案）的預設是不給，與其他
     * 旗標一致。
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('mcp_enabled')
                ->default(false)
                ->after('thinking_enabled')
                ->comment('可用 API key 串接 MCP');
        });

        DB::table('plans')->whereIn('title', ['Pro', 'Advance'])->update(['mcp_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('mcp_enabled');
        });
    }
};
