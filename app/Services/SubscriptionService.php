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
