<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\AiModel;
use Hypervel\Database\Seeder;

/**
 * 方案與模型的初始對應。
 *
 * 明確列出每個方案能用哪些 provider_model，而不是靠模型身上的標籤推導——
 * plan_ai_models 是授權的唯一來源，它的內容就該在這裡看得一清二楚。
 *
 * 採累進：高階方案包含低階方案的所有模型——付了錢卻少掉免費就有的選項並不合理。
 *
 * 只寫入 pivot 完全是空的方案。這張表一旦有人調整過，它就是授權的真相，
 * 重跑不該把手動調整洗掉。
 */
class PlanAiModelSeeder extends Seeder
{
    private const FREE_MODELS = [
        'openai/gpt-4.1-mini',
        'openai/gpt-5-mini',
        'google/gemini-2.5-flash',
    ];

    private const PRO_MODELS = [
        'anthropic/claude-haiku-4.5',
        'google/gemini-2.5-pro',
        'openai/gpt-5',
    ];

    private const ADVANCE_MODELS = [
        'anthropic/claude-sonnet-5',
        'anthropic/claude-opus-5',
    ];

    /**
     * 方案標題 → 可用的 provider_model。標題不在這裡的方案一律只給輕量那組。
     *
     * @return array<string, array<int, string>>
     */
    private static function planModels(): array
    {
        $pro = array_merge(self::FREE_MODELS, self::PRO_MODELS);

        return [
            'Free'    => self::FREE_MODELS,
            'Pro'     => $pro,
            'Advance' => array_merge($pro, self::ADVANCE_MODELS),
        ];
    }

    public function run(): void
    {
        foreach (Plan::query()->get() as $plan) {
            if ($plan->aiModels()->count() > 0) {
                continue;
            }

            $providerModels = self::planModels()[$plan->title] ?? self::FREE_MODELS;

            $modelIds = AiModel::query()
                ->where('enabled', true)
                ->whereIn('provider_model', $providerModels)
                ->pluck('id')
                ->all();

            if ($modelIds !== []) {
                $plan->aiModels()->sync($modelIds);
            }
        }
    }
}
