<?php

declare(strict_types=1);

namespace App\Console\Commands\Stripe;

use Exception;
use App\Models\Plan;
use App\Models\Price;
use Hypervel\Console\Command;
use App\Services\StripeClient;

class Sync extends Command
{
    protected ?string $signature = 'stripe:sync';

    protected string $description = '將現有 Plan / Price 同步至 Stripe（建立 Product 與 Price）';

    public function handle(): void
    {
        $stripe = new StripeClient();
        $plans = Plan::with('prices')->get();

        foreach ($plans as $plan) {
            $this->info("處理方案：{$plan->title}");

            if ($plan->stripe()->exists()) {
                $stripeProductId = $plan->stripe->stripe_id;
                $this->line("  ✓ 已存在 Stripe Product：{$stripeProductId}");
            } else {
                try {
                    $params = ['name' => $plan->title];
                    if (!empty($plan->description)) {
                        $params['description'] = $plan->description;
                    }

                    $product = $stripe->products()->create($params);

                    $plan->stripe()->create([
                        'foreign_type'  => Plan::class,
                        'stripe_id'     => $product->id,
                        'stripe_detail' => $product->toArray(),
                    ]);

                    $stripeProductId = $product->id;
                    $this->line("  ✓ 建立 Stripe Product：{$stripeProductId}");
                } catch (Exception $e) {
                    $this->error("  ✗ 建立 Product 失敗：{$e->getMessage()}");
                    continue;
                }
            }

            foreach ($plan->prices as $price) {
                $label = "{$price->unit} \${$price->price}";

                if ($price->stripe()->exists()) {
                    $this->line("    ✓ Price [{$label}] 已存在：{$price->stripe->stripe_id}");
                    continue;
                }

                [$interval, $intervalCount] = match ($price->unit) {
                    Price::UNIT_QUARTERLY => ['month', 3],
                    Price::UNIT_ANNUALLY  => ['year', 1],
                    default               => ['month', 1],
                };

                try {
                    $stripePrice = $stripe->prices()->create([
                        'product'     => $stripeProductId,
                        'unit_amount' => (int) ($price->price * 100),
                        'currency'    => 'usd',
                        'recurring'   => [
                            'interval'       => $interval,
                            'interval_count' => $intervalCount,
                        ],
                    ]);

                    $price->stripe()->create([
                        'foreign_type'  => Price::class,
                        'stripe_id'     => $stripePrice->id,
                        'stripe_detail' => $stripePrice->toArray(),
                    ]);

                    $this->line("    ✓ 建立 Stripe Price [{$label}]：{$stripePrice->id}");
                } catch (Exception $e) {
                    $this->error("    ✗ 建立 Price [{$label}] 失敗：{$e->getMessage()}");
                }
            }
        }

        $this->info('同步完成。');
    }
}
