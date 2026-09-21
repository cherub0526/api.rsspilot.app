<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Advance 方案的思考力度調成 medium，其餘維持 config 的預設值（low）。
     *
     * 旋鈕放在 `plans.ai_routing` 而不是新開一欄：思考力度跟 cost_tier、max_price
     * 一樣是**每個方案的成本設定**，本來就該跟它們放在一起。**刻意不用
     * `ai_quality`**——那一欄是定價頁的行銷文案，與成本刻意不綁定（見
     * `Plan::aiRouting()` 的註解）。
     *
     * seeder 已經一併改了，但正式與 staging 的資料列是當初就建好的、不會因為改
     * seeder 而改變，所以需要這一支。
     *
     * 只在還沒設定過 reasoning 時才寫入，維持冪等；已經被人手動調過的值不會被蓋掉。
     */
    public function up(): void
    {
        $this->each(function (array $routing): ?array {
            if (isset($routing['reasoning'])) {
                return null;
            }

            $routing['reasoning'] = ['effort' => 'medium', 'exclude' => false];

            return $routing;
        });
    }

    public function down(): void
    {
        $this->each(function (array $routing): ?array {
            if (($routing['reasoning']['effort'] ?? null) !== 'medium') {
                return null;
            }

            unset($routing['reasoning']);

            return $routing;
        });
    }

    /**
     * 讀出 Advance 的 ai_routing，交給 $mutate 改寫；回 null 代表這一列不動。
     *
     * ai_routing 是 JSON 欄位，沒辦法用一句 update 改其中一個鍵，所以只能讀出來、
     * 改完再寫回去。
     */
    private function each(callable $mutate): void
    {
        $plan = DB::table('plans')->where('title', 'Advance')->first();

        if (!$plan) {
            return;
        }

        $routing = json_decode((string) ($plan->ai_routing ?? ''), true);

        if (!is_array($routing)) {
            return;
        }

        $next = $mutate($routing);

        if ($next === null) {
            return;
        }

        DB::table('plans')
            ->where('id', $plan->id)
            ->update(['ai_routing' => json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
};
