<?php

declare(strict_types=1);

namespace App\Observers;

use Exception;
use App\Models\Price;
use App\Services\PaddleClient;
use Paddle\SDK\Entities\Shared\Money;
use Paddle\SDK\Entities\Shared\Interval;
use Paddle\SDK\Entities\Shared\TimePeriod;
use Paddle\SDK\Entities\Shared\CurrencyCode;
use Paddle\SDK\Entities\Shared\PriceQuantity;
use Paddle\SDK\Resources\Prices\Operations\CreatePrice;

class PriceObserver
{
    /**
     * Handle the Price "created" event.
     */
    public function created(Price $price): void
    {
        $paddle = new PaddleClient();

        $period = match ($price->unit) {
            Price::UNIT_QUARTERLY => [Interval::Month(), 3],
            Price::UNIT_ANNUALLY  => [Interval::Year(), 1],
            default               => [Interval::Month(), 1]
        };

        try {
            $response = $paddle->prices()->create(
                new CreatePrice(
                    description: '訂閱費用',
                    productId: $price->plan->paddle->paddle_id,
                    unitPrice: new Money(
                        amount: strval($price->price * 100),
                        currencyCode: CurrencyCode::USD()
                    ),
                    billingCycle: new TimePeriod(
                        interval: new Interval($period[0]),
                        frequency: $period[1]
                    ),
                    quantity: new PriceQuantity(1, 1)
                ),
            );

            $price->paddle()->create([
                'foreign_type'  => Price::class,
                'paddle_id'     => $response->id,
                'paddle_detail' => $response,
            ]);
        } catch (Exception $e) {
        }
    }

    /**
     * Handle the Price "updated" event.
     */
    public function updated(Price $price): void
    {
    }

    /**
     * Handle the Price "deleted" event.
     */
    public function deleted(Price $price): void
    {
    }

    /**
     * Handle the Price "restored" event.
     */
    public function restored(Price $price): void
    {
    }

    /**
     * Handle the Price "force deleted" event.
     */
    public function forceDeleted(Price $price): void
    {
    }
}
