<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 截圖從「付費方案都能用」收斂成「只有 Advance」。
     *
     * 前一支（add_screenshot_enabled_to_plans_table）依 download_enabled 把所有付費
     * 方案都打開了，包含 Pro；那支已經進 develop，不能回頭改，所以用這一支修正。
     * seeder 一併調整，但正式與 staging 的資料列是當初建好的，不會因為改 seeder
     * 而改變。
     *
     * 這裡比對 title 是刻意的，和 set_pro_chat_limit_to_twenty 同一種寫法：一次性的
     * 資料修正要指名的就是那幾列既有資料。**執行期的判斷仍然只看
     * plans.screenshot_enabled**，沒有任何程式去比對方案名稱。
     *
     * 條件帶上舊值是為了冪等，同時避免覆蓋掉有人針對這一列做過的其他調整。
     */
    public function up(): void
    {
        DB::table('plans')
            ->where('title', 'Pro')
            ->where('screenshot_enabled', true)
            ->update(['screenshot_enabled' => false]);
    }

    public function down(): void
    {
        DB::table('plans')
            ->where('title', 'Pro')
            ->where('screenshot_enabled', false)
            ->update(['screenshot_enabled' => true]);
    }
};
