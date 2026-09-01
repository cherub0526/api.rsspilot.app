<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Pro 的每日 AI 對話上限由 30 調整為 20。
     *
     * seeder 已一併修正，但正式與 staging 的資料列是當初就建好的，不會因為改
     * seeder 而改變，所以需要這一支。
     *
     * 條件帶上舊值 30 是為了冪等，同時避免覆蓋掉有人針對這一列做過的其他調整——
     * 值已經不是 30 的資料列一律不動。
     */
    public function up(): void
    {
        DB::table('plans')
            ->where('title', 'Pro')
            ->where('chat_limit', 30)
            ->update(['chat_limit' => 20]);
    }

    public function down(): void
    {
        DB::table('plans')
            ->where('title', 'Pro')
            ->where('chat_limit', 20)
            ->update(['chat_limit' => 30]);
    }
};
