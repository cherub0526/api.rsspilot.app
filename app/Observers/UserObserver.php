<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Models\Subscription;
use App\Services\PaddleClient;
use Paddle\SDK\Resources\Customers\Operations\UpdateCustomer;

class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * 這裡**刻意不建立任何訂閱**。2026-09 之前註冊會自動送一個月 Pro 試用，現在
     * 改成「首次訂閱時免費試用 `Subscription::TRIAL_DAYS` 天」——贈送的時機從註冊
     * 移到結帳，所以新會員一開始沒有訂閱紀錄，直接落到免費方案退路（見
     * `SubscriptionService::getUserSubscriptionPlan()`）。
     *
     * 試用怎麼給：Creem 與 Paddle 都是綁在 product/price 上（`creem:sync` 的
     * `trial_period_days`、`paddle:sync` 的 `trial_period`）、Stripe 是結帳時的
     * `trial_end`；資格判定在 `SubscriptionService::isEligibleForIntroOffer()`，
     * 終生一次。
     *
     * 但**一定**建一筆空的 settings：每個使用者恰好一筆（settings.user_id 有唯一索引），
     * 讀設定的地方不必再處理「還沒有資料列」。空的 data 代表「沒有偏好」——
     * uiLocale() 仍回 null，SetLocale 照樣退回 Accept-Language。註冊當下的語系由
     * 呼叫端接著用 User::seedLocale() 寫進來。
     */
    public function created(User $user): void
    {
        $user->setting()->create(['data' => []]);
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        // Paddle 關閉時不再同步；開著也只同步已經有 Paddle customer 的使用者。
        if (!Subscription::paddleEnabled() || !$user->paddle()->exists()) {
            return;
        }

        (new PaddleClient())->customers()->update(
            $user->paddle->paddle_id,
            new UpdateCustomer(
                email: $user->email,
                name: $user->name
            )
        );
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
