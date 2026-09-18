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
     * 註冊即贈送的試用方案。
     *
     * 用 title 找方案是既有做法，代價要知道：**方案改名或這個方案沒有月價，這裡
     * 會直接 return，不報錯也不寫 log**，新會員就完全沒有訂閱，當場落到免費退路
     * （見 SubscriptionService::getUserSubscriptionPlan()）。動方案名稱時要回來看
     * 這一行。
     */
    private const TRIAL_PLAN_TITLE = 'Pro';

    /**
     * 試用期長度。
     *
     * 沒有任何排程去把過期的試用改成 canceled——`Subscription::scopeActive()` 以
     * `next_date` 判斷，時間一到那筆訂閱就不再算數，使用者自然落到免費方案。
     */
    private const TRIAL_MONTHS = 1;

    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        $trialPlan = Plan::query()->where('title', self::TRIAL_PLAN_TITLE)->first();

        if (!$trialPlan) {
            return;
        }

        $monthlyPrice = $trialPlan->prices()
            ->where('unit', Price::UNIT_MONTHLY)
            ->first();

        if (!$monthlyPrice) {
            return;
        }

        $user->subscriptions()->create([
            'plan_id'        => $trialPlan->id,
            'price_id'       => $monthlyPrice->id,
            'payment_method' => Subscription::PAYMENT_METHOD_TRIAL,
            'status'         => Subscription::STATUS_TRIAL,
            'start_date'     => now(),
            'next_date'      => now()->addMonths(self::TRIAL_MONTHS),
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
