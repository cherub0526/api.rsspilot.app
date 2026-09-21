<?php

declare(strict_types=1);

namespace App\Console\Commands\Paddle;

use Exception;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use Hypervel\Console\Command;
use App\Services\PaddleClient;
use Paddle\SDK\Exceptions\ApiError;
use Paddle\SDK\Entities\Shared\Money;
use Paddle\SDK\Entities\Shared\TaxMode;
use Paddle\SDK\Entities\Shared\Interval;
use Paddle\SDK\Entities\Shared\TimePeriod;
use Paddle\SDK\Entities\Shared\TaxCategory;
use Paddle\SDK\Entities\Shared\CurrencyCode;
use Paddle\SDK\Entities\Shared\PriceQuantity;
use Paddle\SDK\Exceptions\ApiError\ProductApiError;
use Paddle\SDK\Resources\Prices\Operations\CreatePrice;
use Paddle\SDK\Resources\Prices\Operations\UpdatePrice;
use Paddle\SDK\Exceptions\SdkExceptions\MalformedResponse;
use Paddle\SDK\Resources\Products\Operations\CreateProduct;
use Paddle\SDK\Resources\Products\Operations\UpdateProduct;

class Sync extends Command
{
    protected ?string $signature = 'paddle:sync';

    protected string $description = '將現有 Plan / Price 同步至 Paddle（建立 Product 與 Price）';

    public function handle(): void
    {
        $paddle = new PaddleClient();
        $plans = Plan::with('prices')->get();

        foreach ($plans as $plan) {
            $this->info("處理方案：{$plan->title}");

            if ($plan->paddle()->exists()) {
                $paddleProductId = $plan->paddle->paddle_id;
                $this->line("  ✓ 已存在 Paddle Product：{$paddleProductId}");

                try {
                    $paddle->products()->update(
                        $paddleProductId,
                        new UpdateProduct(
                            name: $plan->title,
                            description: $plan->description ?? ''
                        )
                    );
                    $this->line('  ✓ 更新 Paddle Product 完成');
                } catch (ProductApiError $e) {
                } catch (ApiError $e) {
                } catch (MalformedResponse $e) {
                }
            } else {
                try {
                    $product = $paddle->products()->create(
                        new CreateProduct(
                            name: $plan->title,
                            taxCategory: TaxCategory::Standard(),
                            description: $plan->description ?? ''
                        )
                    );

                    $plan->paddle()->create([
                        'foreign_type'  => Plan::class,
                        'paddle_id'     => $product->id,
                        'paddle_detail' => $product,
                    ]);

                    $paddleProductId = $product->id;
                    $this->line("  ✓ 建立 Paddle Product：{$paddleProductId}");
                } catch (ProductApiError $e) {
                    $this->error("  ✗ 建立 Product 失敗：{$e->getMessage()}");
                    continue;
                } catch (ApiError $e) {
                    $this->error("  ✗ 建立 Product 失敗：{$e->getMessage()}");
                    continue;
                } catch (MalformedResponse $e) {
                    $this->error("  ✗ 建立 Product 失敗：{$e->getMessage()}");
                    continue;
                }
            }

            foreach ($plan->prices as $price) {
                $label = "{$price->unit} \${$price->price}";

                if ($price->paddle()->exists()) {
                    $paddlePriceId = $price->paddle->paddle_id;

                    try {
                        // 已存在的 price 也要把 tax_mode 與 trial_period 拉回正確值，
                        // 理由同下方建立時的註解。Paddle 的 price 可以 PATCH（不像
                        // Stripe 要建新的再改映射），所以早建好的那批修得到。
                        $response = $paddle->prices()->update(
                            $paddlePriceId,
                            new UpdatePrice(
                                taxMode: TaxMode::External(),
                                trialPeriod: $this->trialPeriodFor($price)
                            )
                        );

                        $price->paddle->fill(['paddle_detail' => $response])->save();

                        $trialLabel = $this->trialPeriodFor($price) ? '、首月免費' : '';
                        $this->line("    ✓ Price [{$label}] 已存在：{$paddlePriceId}（tax_mode=external{$trialLabel}）");
                    } catch (Exception $e) {
                        $this->error("    ✗ 更新 Price [{$label}] 失敗：{$e->getMessage()}");
                    }

                    continue;
                }

                $period = match ($price->unit) {
                    Price::UNIT_QUARTERLY => [Interval::Month(), 3],
                    Price::UNIT_ANNUALLY  => [Interval::Year(), 1],
                    default               => [Interval::Month(), 1],
                };

                try {
                    $response = $paddle->prices()->create(
                        new CreatePrice(
                            description: '訂閱費用',
                            productId: $paddleProductId,
                            unitPrice: new Money(
                                amount: strval($price->price * 100),
                                currencyCode: CurrencyCode::USD()
                            ),
                            billingCycle: new TimePeriod(
                                interval: new Interval($period[0]),
                                frequency: $period[1]
                            ),
                            // 稅金外加。prices.price 是未稅基準價（見 docs/lore/subscription/pitfalls.md），
                            // 而 Paddle 不指定時會落在 location 模式——那會讓歐盟等含稅慣例地區把
                            // 這個數字當成含稅價，VAT 變成從我們的收入裡扣。
                            taxMode: TaxMode::External(),
                            // 首月免費就是靠這段 trial_period：帶著它結帳時 Paddle
                            // 當下不扣款，next_billed_at 直接落在一個月後。它是綁在
                            // price 上的固定值，對每個結帳的人都一樣——「只送第一次」
                            // 由 PaddleSubscriptionService::applyFreeMonth() 把沒有
                            // 資格的人當場 activate 來守住。
                            trialPeriod: $this->trialPeriodFor($price),
                            quantity: new PriceQuantity(1, 1)
                        )
                    );

                    $price->paddle()->create([
                        'foreign_type'  => Price::class,
                        'paddle_id'     => $response->id,
                        'paddle_detail' => $response,
                    ]);

                    $this->line("    ✓ 建立 Paddle Price [{$label}]：{$response->id}");
                } catch (Exception $e) {
                    $this->error("    ✗ 建立 Price [{$label}] 失敗：{$e->getMessage()}");
                }
            }
        }

        $this->info('同步完成。');
    }

    /**
     * 這個 price 要帶多長的試用期——付費方案是首月免費，免費方案不帶。
     *
     * 年繳也一樣送一個月（`trial_period` 與 `billing_cycle` 在 Paddle 是分開的
     * 兩件事），所以年繳使用者是先免費用一個月，再扣一整年。
     *
     * 免費方案回 `null` 不是「不要動它」而是「明確清成沒有試用」——`UpdatePrice`
     * 的預設值是 `Undefined`（不送這個欄位），`null` 才會真的清掉。$0 的 price
     * 掛試用期沒有意義，留著只會讓 Paddle 的訂閱狀態多一種 trialing 要處理。
     */
    private function trialPeriodFor(Price $price): ?TimePeriod
    {
        if ((float) $price->price <= 0) {
            return null;
        }

        return new TimePeriod(
            interval: new Interval(Interval::Month()),
            frequency: Subscription::FREE_MONTHS
        );
    }
}
