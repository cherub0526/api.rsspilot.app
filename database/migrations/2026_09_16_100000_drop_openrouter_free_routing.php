<?php

declare(strict_types=1);

use App\Models\Config;
use Hypervel\Support\Facades\DB;
use App\Utils\AI\OpenRouterModels;
use App\Utils\AI\OpenRouterRouting;
use App\Jobs\Media\SummaryTranslationJob;
use Hypervel\Database\Migrations\Migration;

/*
 * 把專案裡最後兩條走 `openrouter/free` 的路徑改成 Auto Router 的 low 價格帶。
 *
 * 為什麼放棄免費模型：OpenRouter 的 `:free` 限流是**整個帳號共用**的 20 RPM /
 * 1000 RPD（買 credits 也提不高每分鐘那一道），而專案裡的 Free 方案 chat、延伸
 * 問題、自訂摘要試跑與摘要翻譯全部擠在同一個桶子裡。更糟的是撞牆的後果不是「停
 * 下來」：`RoutedInference` 會把失敗的請求退回用途層，也就是**付費**的 Auto
 * Router，只留一行 log。省錢的設計在用量上來時會自己變成花錢的設計，而且沒有人
 * 會發現。詳見 docs/lore/prompts/pitfalls.md。
 *
 * 純資料修正，不動 schema。
 */
return new class extends Migration {
    /**
     * Free 方案改吃跟 Pro 同一組 low 帶設定（含 max_price 上限）。
     */
    private const FREE_ROUTING = [
        'model'    => 'openrouter/auto',
        'plugins'  => [['id' => OpenRouterRouting::PLUGIN_AUTO_ROUTER, 'cost_tier' => OpenRouterRouting::TIER_LOW]],
        'provider' => ['max_price' => ['prompt' => 0.5, 'completion' => 2]],
    ];

    private const PREVIOUS_FREE_ROUTING = ['model' => 'openrouter/free'];

    public function up(): void
    {
        // 先讓 configs.openrouter_models 與 CLASSES 對齊（補上摘要翻譯這個新用途），
        // 再把它的值明確寫成 openrouter/auto。
        //
        // 不能只靠 sync()：它補的預設值是 `config('ai.default_model')`，而那是
        // 環境變數 AI_DEFAULT_MODEL 決定的——開發機就釘成別的模型。這裡要的是
        // 「Auto Router 的 low 帶」這個明確決定，不是「該環境剛好的預設」。
        OpenRouterModels::sync();
        $this->setTranslationModel('openrouter/auto');

        $this->setTranslationRouting([
            'plugins' => [['id' => OpenRouterRouting::PLUGIN_AUTO_ROUTER, 'cost_tier' => OpenRouterRouting::TIER_LOW]],
        ]);

        $this->setFreePlanRouting(self::FREE_ROUTING, self::PREVIOUS_FREE_ROUTING);
    }

    public function down(): void
    {
        $this->setTranslationRouting(null);
        $this->setTranslationModel(null);

        $this->setFreePlanRouting(self::PREVIOUS_FREE_ROUTING, self::FREE_ROUTING);
    }

    /**
     * 只改還停在舊值的那一列：上線後有人手動調過就不要蓋掉他。
     *
     * 比對刻意在 PHP 裡做，不寫進 where。`plans.ai_routing` 在 Postgres 是真正的
     * `json` 欄位，而 `json` 型別**沒有等號運算子**，`where('ai_routing', '{...}')`
     * 會炸成：
     *
     *   SQLSTATE[42883]: operator does not exist: json = unknown
     *
     * 本機是 sqlite，json 欄位就是 TEXT，同一段 code 完全正常——所以這種寫法在
     * 本機驗不出來，只會在部署時炸（實測 2026-09-18 staging）。
     *
     * @param array<string, mixed> $routing
     * @param array<string, mixed> $expected
     */
    private function setFreePlanRouting(array $routing, array $expected): void
    {
        $plan = DB::table('plans')->where('title', 'Free')->first();

        if ($plan === null) {
            return;
        }

        $current = json_decode((string) ($plan->ai_routing ?? ''), true);

        // == 而不是 ===：兩邊都是關聯陣列，key 的順序不該影響判斷。
        if (!is_array($current) || $current != $expected) {
            return;
        }

        DB::table('plans')
            ->where('id', $plan->id)
            ->update(['ai_routing' => json_encode($routing, JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * 寫入（或移除）摘要翻譯要用的模型，其餘用途原封不動。
     */
    private function setTranslationModel(?string $model): void
    {
        $models = Config::getValue(Config::KEY_OPENROUTER_MODELS);
        $models = is_array($models) ? $models : [];

        $key = $this->purposeKey();

        if ($model === null) {
            unset($models[$key]);
        } else {
            $models[$key] = $model;
        }

        Config::setValue(Config::KEY_OPENROUTER_MODELS, $models);
    }

    /**
     * 寫入（或移除）摘要翻譯的路由參數，其餘用途原封不動。
     *
     * @param null|array<string, mixed> $params null 表示移除這個 key
     */
    private function setTranslationRouting(?array $params): void
    {
        $routing = Config::getValue(Config::KEY_OPENROUTER_ROUTING);
        $routing = is_array($routing) ? $routing : [];

        $key = $this->purposeKey();

        if ($params === null) {
            unset($routing[$key]);
        } else {
            $routing[$key] = $params;
        }

        Config::setValue(Config::KEY_OPENROUTER_ROUTING, $routing);
    }

    /**
     * 兩張對照表共用的 key 形式：FQCN 的反斜線換成斜線（見 OpenRouterModels）。
     */
    private function purposeKey(): string
    {
        return str_replace('\\', '/', SummaryTranslationJob::class);
    }
};
