<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    public function up(): void
    {
        Schema::table('user_sources', function (Blueprint $table) {
            $table->timestamp('synced_at')->nullable()->after('notify')
                ->comment('同步時間點：只把此時間之後第一次出現的影片加進影片庫');
        });

        // 既有訂閱以上線時間為同步時間點，之後不再補舊影片（source-video-picker FR-9）。
        DB::table('user_sources')->update(['synced_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('user_sources', function (Blueprint $table) {
            $table->dropColumn('synced_at');
        });
    }
};
