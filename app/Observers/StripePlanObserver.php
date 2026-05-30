<?php

declare(strict_types=1);

namespace App\Observers;

use Exception;
use App\Models\Plan;
use App\Services\StripeClient;

class StripePlanObserver
{
    public function created(Plan $plan): void
    {
        $stripe = new StripeClient();

        try {
            $product = $stripe->products()->create([
                'name'        => $plan->title,
                'description' => $plan->description ?? '',
            ]);

            $plan->stripe()->create([
                'foreign_type'  => Plan::class,
                'stripe_id'     => $product->id,
                'stripe_detail' => $product->toArray(),
            ]);
        } catch (Exception $e) {
        }
    }

    public function updated(Plan $plan): void
    {
        if (!$plan->stripe()->exists()) {
            return;
        }

        $stripe = new StripeClient();

        try {
            $stripe->products()->update($plan->stripe->stripe_id, [
                'name'        => $plan->title,
                'description' => $plan->description ?? '',
            ]);
        } catch (Exception $e) {
        }
    }
}
