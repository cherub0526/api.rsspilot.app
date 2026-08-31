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
                'download_enabled'       => false,
                'agent_enabled'          => false,
                'advanced_model_enabled' => false,
                'custom_summary_enabled' => false,
                'ai_quality'             => Plan::AI_QUALITY_PRO,
                'prices'                 => [
                    ['unit' => Price::UNIT_MONTHLY, 'price' => 0],
                    ['unit' => Price::UNIT_ANNUALLY, 'price' => 0],
                ],
            ],
            [
                'title'                  => 'Pro',
                'channel_limit'          => 3,
                'video_limit'            => 20,
                'chat_limit'             => 30,
                'download_enabled'       => true,
                'agent_enabled'          => false,
                'advanced_model_enabled' => true,
                'custom_summary_enabled' => true,
                'ai_quality'             => Plan::AI_QUALITY_ADVANCED,
                'prices'                 => [
                    ['unit' => Price::UNIT_MONTHLY, 'price' => 12.99],
                    ['unit' => Price::UNIT_ANNUALLY, 'price' => 129],
                ],
            ],
            [
                'title'                  => 'Advance',
                'channel_limit'          => 5,
                'video_limit'            => 50,
                'chat_limit'             => 50,
                'download_enabled'       => true,
                'agent_enabled'          => true,
                'advanced_model_enabled' => true,
                'custom_summary_enabled' => true,
                'ai_quality'             => Plan::AI_QUALITY_DEEP,
                'prices'                 => [
                    ['unit' => Price::UNIT_MONTHLY, 'price' => 24.99],
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
                    'unit'  => $price['unit'],
                    'price' => $price['price'],
                ]);
            }
        }
    }
}
