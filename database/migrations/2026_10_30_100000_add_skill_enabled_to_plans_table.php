<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 頻道 Skill（檢視完整內容、下載、安裝）是方案權益。
     *
     * 判準用欄位而不是方案名稱，與 `mcp_enabled` 一類的旗標同一套做法。Pro 以上開放；
     * 預設 false——無從判斷權益時（沒有方案）的預設是不給。
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('skill_enabled')
                ->default(false)
                ->after('mcp_enabled')
                ->comment('可檢視、下載頻道 Skill：1 開啟 / 0 關閉');
        });

        DB::table('plans')->whereIn('title', ['Pro', 'Advance'])->update(['skill_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('skill_enabled');
        });
    }
};
