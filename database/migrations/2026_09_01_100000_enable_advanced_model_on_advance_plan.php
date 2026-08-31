<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Advance 方案補上「可選擇進階 AI 模型」的權益。
     *
     * 這一欄從 seeder 建立起就是 Advance 為 false、Pro 為 true——高階方案少了低階方案
     * 已經有的權益，是資料寫錯而不是刻意的分級。seeder 已一併修正，但正式與 staging
     * 的資料列是當初就建好的，不會因為改 seeder 而改變，所以需要這一支。
     *
     * 條件帶上 `advanced_model_enabled = false` 是為了冪等——已經是 true 的資料列不會被
     * 重寫，updated_at 也就不會被無謂地推進。布林只有兩種值，這個條件擋不住「有人刻意
     * 設成 false」的情況；真要保留那種選擇得另外加欄位，目前沒有這個需求。
     */
    public function up(): void
    {
        DB::table('plans')
            ->where('title', 'Advance')
            ->where('advanced_model_enabled', false)
            ->update(['advanced_model_enabled' => true]);
    }

    public function down(): void
    {
        DB::table('plans')
            ->where('title', 'Advance')
            ->where('advanced_model_enabled', true)
            ->update(['advanced_model_enabled' => false]);
    }
};
