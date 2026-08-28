<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Config;
use Hypervel\Database\Seeder;
use App\Utils\AI\OpenRouterModels;

/**
 * 把 class → OpenRouter 模型的對照表寫進 configs 的 `openrouter_models`。
 *
 * 可重複執行：整列不存在就建立，已存在但少了某個用途的 key 就補上預設值，
 * 已經有值的 key 一律不動 —— 線上調過的模型不該被重跑 seeder 蓋回去。
 * 實際的合併規則在 OpenRouterModels::sync()，遷移走的也是同一支，兩邊不會分歧。
 */
class OpenRouterModelsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $before = Config::getValue(Config::KEY_OPENROUTER_MODELS);
        $existed = is_array($before);

        $after = OpenRouterModels::sync();

        if (!isset($this->command)) {
            return;
        }

        if (!$existed) {
            $this->command->info(sprintf(
                'configs.%s 建立完成，共 %d 個用途。',
                Config::KEY_OPENROUTER_MODELS,
                count($after)
            ));

            return;
        }

        $added = array_keys(array_diff_key($after, $before));
        $removed = array_keys(array_diff_key($before, $after));

        if ($added === [] && $removed === []) {
            $this->command->info(sprintf('configs.%s 已是最新，無需變更。', Config::KEY_OPENROUTER_MODELS));

            return;
        }

        if ($added !== []) {
            $this->command->info('補上缺少的用途：' . implode(', ', $added));
        }

        if ($removed !== []) {
            $this->command->info('移除已下架的用途：' . implode(', ', $removed));
        }
    }
}
