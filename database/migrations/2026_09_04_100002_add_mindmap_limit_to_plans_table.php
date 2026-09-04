<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    /**
     * 心智圖與對話是分開的兩個桶子，所以要自己的上限欄位。數字比照對話：
     * 輸入是既有摘要（幾百字）而不是整份逐字稿，單次成本與一次對話相當。
     */
    private const DAILY_LIMITS = [
        'Free'    => 3,
        'Pro'     => 20,
        'Advance' => 50,
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('mindmap_limit')
                ->default(0)
                ->after('chat_limit')
                ->comment('每日心智圖產生次數上限，0表示不限制');
        });

        // 新欄位預設 0，而 0 的語意是「不限制」——不補值等於這個額度沒有生效。
        foreach (self::DAILY_LIMITS as $title => $limit) {
            DB::table('plans')
                ->where('title', $title)
                ->where('mindmap_limit', 0)
                ->update(['mindmap_limit' => $limit]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('mindmap_limit');
        });
    }
};
