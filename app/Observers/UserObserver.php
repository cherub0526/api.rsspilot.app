<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Services\PaddleClient;
use Paddle\SDK\Resources\Customers\Operations\UpdateCustomer;

class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * 這裡**刻意不建立任何訂閱**。2026-09 之前註冊會自動送一個月 Pro 試用，現在
     * 改成「首次訂閱時，第一個月免費」——贈送的時機從註冊移到結帳，所以新會員
     * 一開始沒有訂閱紀錄，直接落到免費方案退路（見
     * `SubscriptionService::getUserSubscriptionPlan()`）。
     *
     * 免費月怎麼給：Paddle 是 price 上的 `trial_period`（`paddle:sync` 設定）、
     * Stripe 是結帳時的 `trial_end`；資格判定在
     * `SubscriptionService::isEligibleForFreeMonth()`，終生一次。
     */
    public function created(User $user): void
    {
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
