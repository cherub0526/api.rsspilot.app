<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 「AI 會先思考再回答」變成一個方案權益。
     *
     * **與 `ai_routing.reasoning` 是兩件事**，分工跟 `agent_enabled` 與
     * `ai_routing` 完全一樣：
     *
     * - 這一欄：**能不能思考**。產品決定、定價頁讀它、伺服器據它決定要不要
     *   向上游要推理內容
     * - `ai_routing.reasoning.effort`：**想多久**。成本設定，跟 cost_tier、
     *   max_price 放在一起
     *
     * 在此之前是所有方案都思考（ChatController 寫死 true），連免費方案也是。
     * 推理 token 按 output 計價，而免費方案的成本天花板本來就只有約 $0.6/月。
     *
     * 預設 false：無從判斷權益時的預設是不給，與其他旗標一致。只有 Advance 開——
     * 與 `agent_enabled` 同一個層級，兩者合起來就是「Agent 能力」。Pro 與免費方案
     * 在此之前其實也在思考（寫死 true），這支等於把它們關掉。
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('thinking_enabled')
                ->default(false)
                ->after('agent_enabled')
                ->comment('AI 會先思考再回答');
        });

        DB::table('plans')->where('title', 'Advance')->update(['thinking_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('thinking_enabled');
        });
    }
};
