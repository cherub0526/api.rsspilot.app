<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            // null = 全站共用的摘要（既有資料全屬此類）；有值 = 只屬於該使用者。
            // 兩者共存於同一張表，讀取時依使用者語系決定優先順序。
            $table->foreignUlid('user_id')->nullable()->index()->after('media_id')->comment('使用者 ID，null 為全站共用');
        });
    }

    public function down(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};
