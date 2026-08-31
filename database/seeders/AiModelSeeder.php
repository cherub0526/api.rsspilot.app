<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiModel;
use Hypervel\Database\Seeder;

/**
 * 使用者可選的 AI 模型型錄。
 *
 * provider_model 全部取自 OpenRouter 的實際型錄（https://openrouter.ai/api/v1/models），
 * 不是照著前端寫死的名稱回推——猜出來的 slug 會在推論當下才 404。
 *
 * 分層依據是輸出價格（每百萬 token）：free ≤ $2.5、pro ≤ $10、advance 為旗艦。
 * 價格會變，調整分層時請重新對一次型錄，不要只改這裡的數字註解。
 *
 * updateOrCreate 以 provider_model 為鍵，重跑只會補缺的、更新名稱與分層，
 * 不會把線上手動停用（enabled = false）以外的欄位洗掉。
 */
class AiModelSeeder extends Seeder
{
    /**
     * @var array<int, array{provider_model: string, name: string}>
     */
    private const MODELS = [
        // 輕量：輸出 $1.60 / $2.00 / $2.50 每百萬 token
        ['provider_model' => 'openai/gpt-4.1-mini', 'name' => 'GPT-4.1 Mini'],
        ['provider_model' => 'openai/gpt-5-mini', 'name' => 'GPT-5 Mini'],
        ['provider_model' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash'],

        // 中階：輸出 $5.00 / $10.00 / $10.00
        ['provider_model' => 'anthropic/claude-haiku-4.5', 'name' => 'Claude Haiku 4.5'],
        ['provider_model' => 'google/gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro'],
        ['provider_model' => 'openai/gpt-5', 'name' => 'GPT-5'],

        // 旗艦：輸出 $10.00 / $25.00
        ['provider_model' => 'anthropic/claude-sonnet-5', 'name' => 'Claude Sonnet 5'],
        ['provider_model' => 'anthropic/claude-opus-5', 'name' => 'Claude Opus 5'],
    ];

    public function run(): void
    {
        foreach (self::MODELS as $sort => $model) {
            AiModel::updateOrCreate(
                ['provider_model' => $model['provider_model']],
                [
                    'name' => $model['name'],
                    'sort' => $sort,
                ]
            );
        }
    }
}
