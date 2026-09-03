<?php

declare(strict_types=1);

use Carbon\Carbon;
use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 既有使用者一律視為已驗證（grandfather）。
     *
     * 他們在 email 成為登入識別之前就已經在用產品，沒有經過驗證流程；若把他們標成
     * 未驗證，登入會被擋下，而且會被 24 小時清除未驗證帳號的排程掃掉——那會刪到
     * 付費帳號。取捨是：我們無法確認這些信箱真的收得到信（見前端 repo 的
     * docs/auth-email-migration.md「已知取捨」第 1 點），這是刻意的決定。
     *
     * 也因此，清除排程的條件必須包含「建立於這個 migration 之後」。
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereNotNull('email')
            ->update(['email_verified_at' => Carbon::now()]);
    }

    /**
     * 無法還原：這裡沒有記錄「哪些是本次補上的」，把全部清成 null 會連
     * 真正驗證過的新使用者一起打掉。資料修正型 migration 的單向性。
     */
    public function down(): void
    {
    }
};
