<?php

declare(strict_types=1);

namespace App\Observers;

use Exception;
use App\Models\User;
use App\Services\StripeClient;

class StripeUserObserver
{
    public function updated(User $user): void
    {
        if (!$user->stripe()->exists()) {
            return;
        }

        $stripe = new StripeClient();

        try {
            $stripe->customers()->update($user->stripe->stripe_id, [
                'email' => $user->email,
                'name'  => $user->name,
            ]);
        } catch (Exception $e) {
        }
    }
}
