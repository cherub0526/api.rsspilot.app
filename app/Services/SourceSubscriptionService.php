<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Source;
use App\Models\UserSource;
use Carbon\CarbonInterface;

/**
 * 訂閱來源與影片庫之間的規則：訂閱時只收使用者勾選的影片，之後只收同步時間點
 * （`user_sources.synced_at`）之後系統才第一次看到的影片。
 * 見 docs/lore/media/business-rules.md〈訂閱只收同步時間點之後的影片〉。
 */
class SourceSubscriptionService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    /**
     * 同步時把新影片加進一位訂閱者的影片庫，再把同步時間點推進到 `$syncedAt`。
     *
     * 新影片 = `media.created_at` 落在 (synced_at, $syncedAt]，且還不在影片庫。依發布時間
     * 新到舊、受剩餘額度限制；額度不夠而沒加入的影片之後不補，時間點照樣往前推。
     * synced_at 為 null（不該出現：migration 已補齊、訂閱時一定會寫）時不補任何影片。
     */
    public function deliverNewMedia(User $user, Source $source, CarbonInterface $syncedAt): void
    {
        $subscription = UserSource::query()
            ->where('user_id', $user->getKey())
            ->where('source_id', $source->id)
            ->first();

        if ($subscription === null) {
            return;
        }

        $cutoff = $subscription->getAttribute('synced_at');
        $remaining = $this->subscriptionService->remainingVideoQuota($user);

        if ($cutoff !== null && $remaining !== 0) {
            $query = $source->media()
                ->where('media.created_at', '>', $cutoff)
                ->where('media.created_at', '<=', $syncedAt)
                ->whereDoesntHave('users', function ($q) use ($user): void {
                    $q->where('users.id', $user->getKey());
                })
                ->orderByDesc('published_at');

            if ($remaining !== null) {
                $query->limit($remaining);
            }

            $mediaIds = $query->pluck('media.id')->all();

            if ($mediaIds !== []) {
                $user->media()->syncWithoutDetaching($mediaIds);
            }
        }

        $subscription->update(['synced_at' => $syncedAt]);
    }
}
