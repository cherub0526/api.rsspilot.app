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

        try {
            $stripePrice = $stripe->prices()->create([
                'product'     => $price->plan->stripe->stripe_id,
                'unit_amount' => $price->stripeUnitAmount(),
                'currency'    => 'usd',
                'recurring'   => $price->stripeRecurring(),
            ]);

            $price->stripe()->create([
                'foreign_type'  => Price::class,
                'stripe_id'     => $stripePrice->id,
                'stripe_detail' => $stripePrice->toArray(),
            ]);
        } catch (Exception $e) {
        }
    }

    /**
     * Stripe 的 Price 不可修改，改價只能「建新的 → 封存舊的 → 改寫映射」。
     * 少了這段，prices.price 改過之後 stripes 會一直指著舊金額。
     */
    public function updated(Price $price): void
    {
        if (!$price->wasChanged(['price', 'unit'])) {
            return;
        }

        if (!$price->stripe()->exists() || !$price->plan?->stripe()->exists()) {
            return;
        }

        $stripe = new StripeClient();
        $oldStripePriceId = $price->stripe->stripe_id;

        try {
            $stripePrice = $stripe->prices()->create([
                'product'     => $price->plan->stripe->stripe_id,
                'unit_amount' => $price->stripeUnitAmount(),
                'currency'    => 'usd',
                'recurring'   => $price->stripeRecurring(),
            ]);

            $price->stripe->update([
                'stripe_id'     => $stripePrice->id,
                'stripe_detail' => $stripePrice->toArray(),
            ]);

            $stripe->prices()->update($oldStripePriceId, ['active' => false]);
        } catch (Exception $e) {
        }
    }
}
