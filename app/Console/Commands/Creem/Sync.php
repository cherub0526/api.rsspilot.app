<?php

declare(strict_types=1);

namespace App\Console\Commands\Creem;

use Throwable;
use App\Models\Plan;
use App\Models\Creem;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\CreemClient;
use Hypervel\Console\Command;

/**
 * 依 `plans` / `prices` 在 Creem 建立對應的 product。
 *
 * **與 `paddle:sync` 的兩個結構差異：**
 *
 * 1. Creem 沒有 product → 多 price 的階層，價格直接綁在 product 上。所以這裡是
 *    **一個 price 對一個 product**，`plans` 只拿來組名稱，不會在 Creem 建東西。
 * 2. 每個付費 price 要建**兩個** product：含 30 天試用的、與不含的。原因見
 *    CreemSubscriptionService::productIdFor()——Creem 結帳時不能覆寫試用期，
 *    「首月免費只送第一次」只能用選 product 的方式守。
 *
 * 免費方案不會建 product：$0 不需要結帳。
 *
 * 這個指令是**只新增不修改**的。Creem 的 product 一旦有人用它訂閱過就不該再改價，
 * 所以已存在的 price 會直接跳過；要改價請在 Creem 建新 product，再把 `creems`
 * 的對應列指過去（與 Paddle 那邊「price 不可變」的處理原則一致）。
 */
class Sync extends Command
{
    protected ?string $signature = 'creem:sync';

    protected string $description = '依現有 Plan / Price 在 Creem 建立對應的 Product（含試用與不含試用兩個變體）';

    public function handle(): void
    {
        $client = new CreemClient();

        $this->info(sprintf('環境：%s（%s）', $client->isTestMode() ? 'test' : 'live', $client->baseUrl()));

        foreach (Plan::with('prices')->get() as $plan) {
            $this->info("處理方案：{$plan->title}");

            foreach ($plan->prices as $price) {
                $amount = $price->stripeUnitAmount();
                $label = "{$price->unit} \${$price->price}";

                if ($amount <= 0) {
                    $this->line("    - Price [{$label}] 是免費方案，不需要 Creem product");

                    continue;
                }

                foreach ([Creem::VARIANT_STANDARD, Creem::VARIANT_TRIAL] as $variant) {
                    $this->syncVariant($client, $plan, $price, $variant, $amount, $label);
                }
            }
        }

        $this->info('完成。');
    }

    private function syncVariant(
        CreemClient $client,
        Plan $plan,
        Price $price,
        string $variant,
        int $amount,
        string $label
    ): void {
        $existing = $price->creem()->where('variant', $variant)->first();

        if ($existing && $existing->creem_id) {
            $this->line("    ✓ Price [{$label}/{$variant}] 已存在：{$existing->creem_id}");

            return;
        }

        $payload = [
            'name'           => $this->nameFor($plan, $price, $variant),
            'description'    => (string) ($plan->description ?: $plan->title),
            'price'          => $amount,
            'currency'       => 'USD',
            'billing_type'   => 'recurring',
            'billing_period' => $this->billingPeriodFor($price),
        ];

        if ($variant === Creem::VARIANT_TRIAL) {
            // 一個月免費。長度的單一事實來源是 Subscription::FREE_MONTHS，但 Creem
            // 只吃「天數」，所以這裡換算成 30 天——與 Paddle 的 1 個月會有 0–1 天
            // 誤差，那是 Creem 的 API 形狀造成的，不是我們選的。
            $payload['trial_period_days'] = Subscription::FREE_MONTHS * 30;
            $payload['trial_price'] = 0;
        }

        try {
            $product = $client->createProduct($payload);
        } catch (Throwable $e) {
            $this->error("    ✗ 建立 Price [{$label}/{$variant}] 失敗：{$e->getMessage()}");

            return;
        }

        $productId = (string) ($product['id'] ?? '');

        if ($productId === '') {
            $this->error("    ✗ Creem 沒有回傳 product id [{$label}/{$variant}]");

            return;
        }

        $price->creem()->create([
            'foreign_type' => Price::class,
            'creem_id'     => $productId,
            'creem_detail' => $product,
            'variant'      => $variant,
        ]);

        $this->line("    ✓ 建立 Price [{$label}/{$variant}]：{$productId}");
    }

    /**
     * Creem 後台只看得到 product 名稱，所以把方案、週期與變體都寫進去——
     * 八個長得很像的 product 混在一張清單裡，沒有這個就分不出誰是誰。
     */
    private function nameFor(Plan $plan, Price $price, string $variant): string
    {
        $suffix = $variant === Creem::VARIANT_TRIAL ? ' (first month free)' : '';

        return sprintf('%s – %s%s', $plan->title, $price->unit, $suffix);
    }

    private function billingPeriodFor(Price $price): string
    {
        return match ($price->unit) {
            Price::UNIT_QUARTERLY => 'every-3-months',
            Price::UNIT_ANNUALLY  => 'every-year',
            default               => 'every-month',
        };
    }
}
