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
    protected ?string $signature = 'stripe:sync {--dry-run : 只比對並列出差異，不對 Stripe 或資料庫寫入}';

    protected string $description = '將現有 Plan / Price 同步至 Stripe，並修正金額對不上的映射';

    protected bool $dryRun = false;

    public function handle(): void
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('dry-run 模式：只比對，不寫入。');
        }

        $stripe = new StripeClient();
        $plans = Plan::with('prices')->get();

        foreach ($plans as $plan) {
            $this->info("處理方案：{$plan->title}");

            $stripeProductId = $this->syncProduct($stripe, $plan);
            if ($stripeProductId === null) {
                continue;
            }

            foreach ($plan->prices as $price) {
                $this->syncPrice($stripe, $price, $stripeProductId);
            }
        }

        $this->info('同步完成。');
    }

    /**
     * 確保 Plan 有一個可用的 Stripe Product，回傳其 ID；無法取得時回傳 null。
     */
    protected function syncProduct(StripeClient $stripe, Plan $plan): ?string
    {
        if (!$plan->stripe()->exists()) {
            if ($this->dryRun) {
                $this->line('  ! 缺少 Stripe Product，會建立新的');

                return null;
            }

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

                $this->line("  ✓ 建立 Stripe Product：{$product->id}");

                return $product->id;
            } catch (Exception $e) {
                $this->error("  ✗ 建立 Product 失敗：{$e->getMessage()}");

                return null;
            }
        }

        $stripeProductId = $plan->stripe->stripe_id;

        try {
            $product = $stripe->products()->retrieve($stripeProductId);
        } catch (Exception $e) {
            $this->error("  ✗ Stripe 上取不到 Product {$stripeProductId}：{$e->getMessage()}");

            return null;
        }

        if (!$product->active) {
            $this->warn("  ! Stripe Product {$stripeProductId} 已封存，price 仍會掛在它底下");
        } else {
            $this->line("  ✓ 已存在 Stripe Product：{$stripeProductId}");
        }

        return $stripeProductId;
    }

    /**
     * 建立缺少的 Stripe Price，或修正金額、週期、掛載 Product 對不上的映射。
     */
    protected function syncPrice(StripeClient $stripe, Price $price, string $stripeProductId): void
    {
        $label = "{$price->unit} \${$price->price}";

        if (!$price->stripe()->exists()) {
            $this->createPrice($stripe, $price, $stripeProductId, $label);

            return;
        }

        $stripePriceId = $price->stripe->stripe_id;
        $differences = $this->diffPrice($stripe, $price, $stripeProductId, $stripePriceId);

        if ($differences === []) {
            $this->line("    ✓ Price [{$label}] 一致：{$stripePriceId}");

            return;
        }

        $this->warn("    ! Price [{$label}] 與 {$stripePriceId} 不一致：" . implode('、', $differences));

        if ($this->dryRun) {
            return;
        }

        $this->repointPrice($stripe, $price, $stripeProductId, $stripePriceId, $label);
    }

    /**
     * 列出 DB 的 Price 與其映射的 Stripe Price 之間的差異。
     *
     * @return array<int, string>
     */
    protected function diffPrice(StripeClient $stripe, Price $price, string $stripeProductId, string $stripePriceId): array
    {
        try {
            $stripePrice = $stripe->prices()->retrieve($stripePriceId);
        } catch (Exception $e) {
            return ["Stripe 上取不到（{$e->getMessage()}）"];
        }

        $differences = [];
        $expectedAmount = $price->stripeUnitAmount();
        $expectedRecurring = $price->stripeRecurring();

        if ($stripePrice->unit_amount !== $expectedAmount) {
            $differences[] = sprintf(
                '金額 %s → 應為 %s',
                number_format($stripePrice->unit_amount / 100, 2),
                number_format($expectedAmount / 100, 2)
            );
        }

        $interval = $stripePrice->recurring->interval ?? null;
        $intervalCount = $stripePrice->recurring->interval_count ?? null;
        if ($interval !== $expectedRecurring['interval'] || $intervalCount !== $expectedRecurring['interval_count']) {
            $differences[] = sprintf(
                '週期 %s → 應為 %s',
                $interval === null ? 'one_time' : "{$intervalCount} {$interval}",
                "{$expectedRecurring['interval_count']} {$expectedRecurring['interval']}"
            );
        }

        $product = is_string($stripePrice->product) ? $stripePrice->product : $stripePrice->product->id;
        if ($product !== $stripeProductId) {
            $differences[] = "掛在 {$product} → 應為 {$stripeProductId}";
        }

        if (!$stripePrice->active) {
            $differences[] = '已封存';
        }

        return $differences;
    }

    protected function createPrice(StripeClient $stripe, Price $price, string $stripeProductId, string $label): void
    {
        if ($this->dryRun) {
            $this->line("    ! Price [{$label}] 缺少映射，會建立新的");

            return;
        }

        try {
            $stripePrice = $this->makeStripePrice($stripe, $price, $stripeProductId);

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

    /**
     * Stripe 的 Price 不可修改，所以改價一律是「建新的 → 封存舊的 → 改寫映射」。
     */
    protected function repointPrice(StripeClient $stripe, Price $price, string $stripeProductId, string $oldStripePriceId, string $label): void
    {
        try {
            $stripePrice = $this->makeStripePrice($stripe, $price, $stripeProductId);
        } catch (Exception $e) {
            $this->error("    ✗ 建立新 Price [{$label}] 失敗：{$e->getMessage()}");

            return;
        }

        $price->stripe->update([
            'stripe_id'     => $stripePrice->id,
            'stripe_detail' => $stripePrice->toArray(),
        ]);

        $this->line("    ✓ Price [{$label}] 改指向 {$stripePrice->id}");

        try {
            $stripe->prices()->update($oldStripePriceId, ['active' => false]);
            $this->line("    ✓ 封存舊 Price：{$oldStripePriceId}");
        } catch (Exception $e) {
            $this->warn("    ! 封存舊 Price {$oldStripePriceId} 失敗：{$e->getMessage()}");
        }
    }

    protected function makeStripePrice(StripeClient $stripe, Price $price, string $stripeProductId): \Stripe\Price
    {
        return $stripe->prices()->create([
            'product'     => $stripeProductId,
            'unit_amount' => $price->stripeUnitAmount(),
            'currency'    => 'usd',
            'recurring'   => $price->stripeRecurring(),
        ]);
    }
}
