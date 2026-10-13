<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Source;
use App\Models\Subscription;

class SubscriptionService
{
    public function getUserSubscription(string $userId)
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->active()
            ->orderBy('start_date', 'desc')
            ->first();
    }

    /**
     * 這個帳號還能不能領首月免費。
     *
     * 規則是**終生一次**：只要曾經有一筆訂閱真的成立過（走完結帳、或留下過交易
     * 紀錄），之後再訂閱一律當場計費。用「成立過」而不是「目前有沒有在訂閱」是
     * 因為 Paddle 取消訂閱時我們自己的 `subscriptions.status` 不會被改寫（只有
     * Stripe 的 webhook 會寫 `canceled`），拿當下狀態判斷會讓「訂閱 → 取消 →
     * 再訂閱」變成無限續杯的手法。
     *
     * 排除兩種紀錄：
     *
     * - `payment_method = trial`：2026-09 之前註冊即贈送的那批試用訂閱。那是系統
     *   送的、不是使用者買的，不該吃掉他的首月免費。
     * - `$excludeSubscriptionId`：結帳當下那筆 `paying` 紀錄本身。它是
     *   `SubscriptionsController::store()` 在導去結帳前就先建好的。
     *
     * 連軟刪除的紀錄都要算（`withTrashed()`）——訂閱成立過這件事不會因為資料列
     * 被刪掉而沒發生過。
     */
    public function isEligibleForFreeMonth(string $userId, ?string $excludeSubscriptionId = null): bool
    {
        $query = Subscription::withTrashed()
            ->where('user_id', $userId)
            ->where('payment_method', '!=', Subscription::PAYMENT_METHOD_TRIAL)
            ->where(function ($builder) {
                $builder->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_CANCELED])
                    ->orWhereHas('transactions');
            });

        if ($excludeSubscriptionId !== null) {
            $query->where('id', '!=', $excludeSubscriptionId);
        }

        return !$query->exists();
    }

    /**
     * 這個帳號還能不能領**新客優惠**。
     *
     * 月繳的新客優惠是首月免費，年繳的是首年折價——**兩者共用同一個終生一次的
     * 閘門**，而且刻意只有這一個。分開算的話「月繳用掉首月免費 → 取消 → 改訂
     * 年繳拿首年折價」就能一個人吃兩份。
     *
     * 這支只是 isEligibleForFreeMonth() 的別名，存在的理由是讓年繳那條路的呼叫端
     * 讀起來不是在問一個跟它無關的問題（它不發「免費月」）。資格判斷只有一份
     * 實作，見上面那支的註解。
     */
    public function isEligibleForIntroOffer(string $userId, ?string $excludeSubscriptionId = null): bool
    {
        return $this->isEligibleForFreeMonth($userId, $excludeSubscriptionId);
    }

    public function getUserSubscriptionPlan(?Subscription $subscription)
    {
        if ($subscription) {
            $plan = $subscription->plan()->first();
        }

        // 如果沒有找訂閱的方案，預設就是免費的月訂閱方案
        if (!isset($plan) || !$plan) {
            $plan = Plan::query()->whereHas('prices', function ($builder) {
                $builder->where('unit', Price::UNIT_MONTHLY)->where('price', 0);
            })->first();
        }

        return $plan;
    }

    /**
     * Write media from a source into a user's userables, respecting their
     * 30-day video quota. Only new entries are inserted — existing ones are
     * never removed — so unsubscribing a source cannot reduce usage count.
     */
    public function syncSourceMediaToUserables(User $user, Source $source): void
    {
        $betweenDays = [now()->subDays(30)->startOfDay(), now()->endOfDay()];

        $plan = $this->getUserSubscriptionPlan($this->getUserSubscription($user->id));

        $usedCount = $user->media()
            ->countsTowardQuota()
            ->whereBetween('userables.created_at', $betweenDays)
            ->count();

        $limit = null;
        if ($plan !== null && $plan->video_limit > 0) {
            $remaining = $plan->video_limit - $usedCount;
            if ($remaining <= 0) {
                return;
            }
            $limit = $remaining;
        }

        $query = $source->media()
            ->whereDoesntHave('users', fn ($q) => $q->where('id', $user->getKey()))
            ->orderByDesc('published_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $newIds = $query->pluck('media.id')->all();

        if (empty($newIds)) {
            return;
        }

        $user->media()->syncWithoutDetaching($newIds);
    }
}
