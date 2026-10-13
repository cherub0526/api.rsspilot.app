<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * 影片額度：一位使用者在滾動 30 天窗口裡還能加幾支影片。
 *
 * 三個呼叫端共用這一份算法——POST /v1/media 的擋閘、RSS 同步一次最多寫幾筆
 * （SubscriptionService::syncSourceMediaToUserables）、以及 usage API 顯示的
 * 數字。以前三邊各自寫窗口與上限，窗口甚至差一天（30 vs 29），側欄會出現
 * 「4 / 3」這種自己打自己的畫面。
 *
 * 用量的池子只有一個：`userables` 裡 30 天內新增的筆數。手動加入與 RSS 同步
 * 分開計算的話，手動加入就成了繞過 RSS 上限的後門。
 *
 * **新帳號加碼**（config `subscription.video_boost`）：帳號建立後的前幾天把上限
 * 墊高到 max(方案額度, 加碼額度)，讓人先用到有感再撞牆。兩件事刻意分開處理：
 *
 * - 上限：加碼期間取較大者，所以首週就升級的人不會被加碼值壓低。
 * - 窗口：加碼結束後，窗口起點不早於加碼結束的那一刻。否則第 8 天的人會帶著
 *   前 7 天用掉的 10 支去對 3 支的上限，等於把加碼倒扣回去，要等三週才能再加
 *   一支——那不是「先有感再撞牆」，是先給再拿走。
 */
class VideoQuotaService
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    /**
     * 當下的額度狀態，不改變任何用量。
     */
    public function snapshot(User $user): VideoQuotaSnapshot
    {
        $plan = $this->subscriptions->getUserSubscriptionPlan(
            $this->subscriptions->getUserSubscription((string) $user->getKey())
        );

        $planLimit = $plan === null ? 0 : (int) $plan->video_limit;
        $boostLimit = max(0, (int) config('subscription.video_boost.limit', 0));
        $boostDays = max(0, (int) config('subscription.video_boost.days', 0));

        $createdAt = $user->created_at;

        // 方案本身就不限制（0）時加碼沒有意義，一律當作沒有加碼，窗口也維持原樣。
        $boostEndsAt = ($boostDays > 0 && $boostLimit > 0 && $planLimit > 0 && $createdAt !== null)
            ? $createdAt->copy()->addDays($boostDays)
            : null;

        $boostActive = $boostEndsAt !== null && $boostEndsAt->isFuture();

        $windowStart = $this->windowStart($boostActive ? null : $boostEndsAt);

        $used = $user->media()
            ->countsTowardQuota()
            ->where('userables.created_at', '>=', $windowStart)
            ->count();

        return new VideoQuotaSnapshot(
            limit: $boostActive ? max($planLimit, $boostLimit) : $planLimit,
            planLimit: $planLimit,
            used: $used,
            windowStart: $windowStart,
            boostLimit: $boostLimit,
            boostEndsAt: $boostEndsAt,
            boostActive: $boostActive,
        );
    }

    /**
     * 用量窗口的起點。
     *
     * @param null|CarbonInterface $boostEndedAt 已經結束的加碼結束時刻；傳 null 代表
     *                                           不套用加碼地板（還在加碼中、或方案不限制）
     */
    private function windowStart(?CarbonInterface $boostEndedAt): CarbonInterface
    {
        // subDays(29) 而不是 30：下界取 startOfDay、上界是現在，29 天前到今天剛好
        // 是 30 個日期。用 30 會變成 31 天，與定價頁寫的「每 30 天」對不上。
        $start = now()->subDays(29)->startOfDay();

        if ($boostEndedAt !== null && $boostEndedAt->greaterThan($start)) {
            return $boostEndedAt;
        }

        return $start;
    }
}
