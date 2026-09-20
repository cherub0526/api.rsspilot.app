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
