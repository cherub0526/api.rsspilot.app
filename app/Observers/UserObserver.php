<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\PaddleClient;
use Paddle\SDK\Resources\Customers\Operations\UpdateCustomer;

class UserObserver
{
    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $advancePlan = Plan::query()->where('title', 'Advance')->first();

        if (!$advancePlan) {
            return;
        }

        $monthlyPrice = $advancePlan->prices()
            ->where('unit', Price::UNIT_MONTHLY)
            ->first();

        if (!$monthlyPrice) {
            return;
        }

        $user->subscriptions()->create([
            'plan_id'        => $advancePlan->id,
            'price_id'       => $monthlyPrice->id,
            'payment_method' => Subscription::PAYMENT_METHOD_TRIAL,
            'status'         => Subscription::STATUS_TRIAL,
            'start_date'     => now(),
            'next_date'      => now()->addDays(14),
        ]);
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        $paddle = new PaddleClient();

        if ($user->paddle()->exists()) {
            $paddle->customers()->update(
                $user->paddle->paddle_id,
                new UpdateCustomer(
                    email: $user->email,
                    name: $user->name
                )
            );
        }
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
    }
}
