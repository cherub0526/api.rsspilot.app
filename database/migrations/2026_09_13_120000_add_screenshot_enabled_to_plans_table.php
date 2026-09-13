<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 播放器截圖是付費功能：Pro 以上才開放。
     *
     * 成本落在帶圖提問而不是截圖本身——vision 推論的單次成本明顯高於純文字，而
     * 每日 chat 額度沒有為帶圖另外加權（見 docs/lore/prompts/business-rules.md）。
     * 所以閘門設在「能不能截」與「能不能帶圖問」兩處。
     *
     * 新欄位預設 false，緊接著把既有的付費方案打開：schema 與回填放在同一支是因為
     * 這個欄位是全新的，沒有既存狀態要尊重，一次做完不會有中間態。判斷條件用
     * download_enabled 而不是方案名稱——它已經是「這是付費方案」的既有表達方式，
     * 比對 title 會在改名或新增方案時失準。
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('screenshot_enabled')
                ->default(false)
                ->after('custom_summary_enabled')
                ->comment('可截取播放器畫面並帶進 AI 對話');
        });

        DB::table('plans')->where('download_enabled', true)->update(['screenshot_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('screenshot_enabled');
        });
    }
};
