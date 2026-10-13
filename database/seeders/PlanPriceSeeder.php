<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Price;
use Hypervel\Database\Seeder;

class PlanPriceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'title'                  => 'Free',
                'channel_limit'          => 1,
                'video_limit'            => 3,
                'chat_limit'             => 3,
                'mindmap_limit'          => 3,
                'download_enabled'       => false,
                'agent_enabled'          => false,
                'thinking_enabled'       => false,
                'mcp_enabled'            => false,
                'advanced_model_enabled' => false,
                'custom_summary_enabled' => false,
                'screenshot_enabled'     => false,
                'ai_quality'             => Plan::AI_QUALITY_PRO,
                'ai_routing'             => [
                    'model'    => 'openrouter/auto',
                    'plugins'  => [['id' => 'auto-router', 'cost_tier' => 'low']],
                    'provider' => ['max_price' => ['prompt' => 0.5, 'completion' => 2]],
                ],
                'prices' => [
                    ['unit' => Price::UNIT_MONTHLY, 'price' => 0],
                    ['unit' => Price::UNIT_ANNUALLY, 'price' => 0],
                ],
            ],
            [
                'title'                  => 'Pro',
                'channel_limit'          => 3,
                'video_limit'            => 20,
                'chat_limit'             => 20,
                'mindmap_limit'          => 20,
                'download_enabled'       => true,
                'agent_enabled'          => false,
                'thinking_enabled'       => false,
                'mcp_enabled'            => true,
                'advanced_model_enabled' => true,
                'custom_summary_enabled' => true,
                // 截圖只開放給 Advance
                'screenshot_enabled' => false,
                'ai_quality'         => Plan::AI_QUALITY_ADVANCED,
                'ai_routing'         => [
                    'model'    => 'openrouter/auto',
                    'plugins'  => [['id' => 'auto-router', 'cost_tier' => 'low']],
                    'provider' => ['max_price' => ['prompt' => 0.5, 'completion' => 2]],
                ],
                'prices' => [
                    ['unit' => Price::UNIT_MONTHLY, 'price' => 12.99],
                    // **沒有首期折價**（`first_period_price` 不填 = null）。owner 在
                    // 2026-10-13 決定兩個方案都不降價，新客優惠統一成
                    // `Subscription::TRIAL_DAYS` 天免費試用，月繳與年繳都適用。
                    //
                    // 日後真要折價，填這個欄位會讓年繳從試用那條路切到折扣碼那條路
                    // （兩者互斥，見 CreemSubscriptionService::grantsTrial()），而且
                    // 已部署的環境要同時附一支 migration（理由見 pricing-cost-model §6）。
                    ['unit' => Price::UNIT_ANNUALLY, 'price' => 129],
                ],
            ],
            [
                'title'                  => 'Advance',
                'channel_limit'          => 5,
                'video_limit'            => 50,
                'chat_limit'             => 50,
                'mindmap_limit'          => 50,
                'download_enabled'       => true,
                'agent_enabled'          => true,
                'thinking_enabled'       => true,
                'mcp_enabled'            => true,
                'advanced_model_enabled' => true,
                'custom_summary_enabled' => true,
                'screenshot_enabled'     => true,
                'ai_quality'             => Plan::AI_QUALITY_DEEP,
                'ai_routing'             => [
                    'model'   => 'openrouter/auto',
                    'plugins' => [['id' => 'auto-router', 'cost_tier' => 'medium']],
                    // 只有 Advance 想得比較久。其餘方案不指定，吃 config 的預設值
                    // （ai.chat.reasoning_effort，目前是 low）——推理 token 按 output
                    // 計價，調高等於直接墊高每日提問額度的成本天花板。
                    'reasoning' => ['effort' => 'medium', 'exclude' => false],
                    'provider'  => ['max_price' => ['prompt' => 1.5, 'completion' => 5]],
                ],
                'prices' => [
                    ['unit' => Price::UNIT_MONTHLY, 'price' => 24.99],
                    // Advance 年繳**沒有**首年折價，而且比 Pro 更不該有：典型使用的
                    // 成本（$15.52/月）就已經吃掉年繳實收的 78%，折到 $189 之後典型
                    // 毛利是 −3%——不是極端使用者小虧，是平均每一位都虧。要折價得先
                    // 動 pricing-cost-model §5 那三個旋鈕。
                    ['unit' => Price::UNIT_ANNUALLY, 'price' => 249],
                ],
            ],
        ];

        foreach ($plans as $key => $plan) {
            $prices = $plan['prices'];
            unset($plan['prices']);

            $entity = Plan::create([
                ...$plan,
                'sort' => $key,
            ]);

            foreach ($prices as $price) {
                $entity->prices()->create([
                    'unit'               => $price['unit'],
                    'price'              => $price['price'],
                    'first_period_price' => $price['first_period_price'] ?? null,
                ]);
            }
        }
    }
}
