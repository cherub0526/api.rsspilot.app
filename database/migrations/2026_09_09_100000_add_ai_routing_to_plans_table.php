<?php

declare(strict_types=1);

use App\Utils\BaseMigration;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hyperf\Database\Schema\Blueprint;

return new class extends BaseMigration {
    /**
     * 每個方案送給 OpenRouter 的路由設定，原封不動當成請求 body 的一部分。
     *
     * 與 `ai_quality` 是兩件事：那一欄是定價頁的行銷文案（前端 switch 成
     * 「Pro / Advanced / Deep」），這一欄是實際路由。兩者需要獨立變動——調成本
     * 不該被迫改文案，改文案也不該動到成本。
     *
     * Free 用 `openrouter/free`（隨機挑免費模型，不計費），Pro 與 Advance 走
     * Auto Router 的不同價格帶。`max_price` 才是真正的成本上限，`cost_tier`
     * 只是價格「帶」——詳見 docs/lore/prompts/pitfalls.md。
     */
    private const ROUTING = [
        'Free' => [
            // 免費路由器不吃 auto-router 的 plugin，也沒有計費可言，所以只有 model。
            'model' => 'openrouter/free',
        ],
        'Pro' => [
            'model'    => 'openrouter/auto',
            'plugins'  => [['id' => 'auto-router', 'cost_tier' => 'low']],
            'provider' => ['max_price' => ['prompt' => 0.5, 'completion' => 2]],
        ],
        'Advance' => [
            'model'    => 'openrouter/auto',
            'plugins'  => [['id' => 'auto-router', 'cost_tier' => 'medium']],
            'provider' => ['max_price' => ['prompt' => 1.5, 'completion' => 5]],
        ],
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('ai_routing')
                ->nullable()
                ->after('ai_quality')
                ->comment('OpenRouter 路由設定 JSON；null 表示不覆寫，退回用途層設定');
        });

        // 既有資料列不會被 seeder 補值（seeder 的規則是不存在才建立），所以在這裡回填。
        foreach (self::ROUTING as $title => $routing) {
            DB::table('plans')
                ->where('title', $title)
                ->whereNull('ai_routing')
                ->update(['ai_routing' => json_encode($routing, JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('ai_routing');
        });
    }
};
