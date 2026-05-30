<?php

declare(strict_types=1);

namespace App\Observers;

use Exception;
use App\Models\Price;
use App\Services\StripeClient;

class StripePriceObserver
{
    public function created(Price $price): void
    {
        $stripe = new StripeClient();

        [$interval, $intervalCount] = match ($price->unit) {
            Price::UNIT_QUARTERLY => ['month', 3],
            Price::UNIT_ANNUALLY  => ['year', 1],
            default               => ['month', 1],
        };

        try {
            $stripePrice = $stripe->prices()->create([
                'product'        => $price->plan->stripe->stripe_id,
                'unit_amount'    => (int) ($price->price * 100),
                'currency'       => 'usd',
                'recurring'      => [
                    'interval'       => $interval,
                    'interval_count' => $intervalCount,
                ],
            ]);

            $price->stripe()->create([
                'foreign_type'  => Price::class,
                'stripe_id'     => $stripePrice->id,
                'stripe_detail' => $stripePrice->toArray(),
            ]);
        } catch (Exception $e) {
        }
    }
}
