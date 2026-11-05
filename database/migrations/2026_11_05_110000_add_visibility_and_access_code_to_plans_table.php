<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Utils\BaseMigration;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('visibility', 16)->default(Plan::VISIBILITY_PUBLIC)->index()
                ->comment('可見度：public 出現在定價頁 / hidden 只能透過專屬連結訂閱');
            // unique 讓 hidden API 用連結碼查方案時只會命中一筆
            $table->string('access_code', 32)->nullable()->unique()
                ->comment('隱藏方案的專屬連結碼（32 字元英數），public 方案為 NULL');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropUnique(['access_code']);
            $table->dropIndex(['visibility']);
            $table->dropColumn(['visibility', 'access_code']);
        });
    }
};
