<?php

declare(strict_types=1);

namespace App\Console\Commands\Paddle;

use Exception;
use App\Models\Plan;
use App\Models\Price;
use App\Services\PaddleClient;
use Hypervel\Console\Command;
use Paddle\SDK\Exceptions\ApiError;
use Paddle\SDK\Entities\Shared\Money;
use Paddle\SDK\Entities\Shared\Interval;
use Paddle\SDK\Entities\Shared\TimePeriod;
use Paddle\SDK\Entities\Shared\TaxCategory;
use Paddle\SDK\Entities\Shared\CurrencyCode;
use Paddle\SDK\Entities\Shared\PriceQuantity;
use Paddle\SDK\Exceptions\ApiError\ProductApiError;
use Paddle\SDK\Resources\Prices\Operations\CreatePrice;
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
        $plans  = Plan::with('prices')->get();

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
                    $this->line("    ✓ Price [{$label}] 已存在：{$price->paddle->paddle_id}");
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
}
