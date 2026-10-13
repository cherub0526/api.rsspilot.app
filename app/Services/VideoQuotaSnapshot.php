<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * 一位使用者當下的影片額度狀態。
 *
 * 影片額度與對話／心智圖不同，不是自然日而是 30 天滾動窗口，用量就是
 * `userables.created_at` 落在窗口裡的筆數（見 VideoQuotaService），所以它不共用
 * DailyQuotaSnapshot，而是自己一個值物件。
 *
 * 由 VideoQuotaService 產出，同時餵給 POST /v1/media 的擋閘、RSS 同步的筆數上限
 * 與 usage API——三邊看到的數字才不會有三套算法。
 */
final class VideoQuotaSnapshot
{
    /**
     * @param int $limit 生效上限（已套用新帳號加碼），0 表示不限制
     * @param int $planLimit 方案本身的上限，0 表示不限制。加碼結束後會回到這個值
     * @param int $used 窗口內已用的影片數
     * @param CarbonInterface $windowStart 用量窗口的起點，usage API 要據此解釋數字
     * @param int $boostLimit 設定檔裡的加碼上限（config subscription.video_boost.limit）
     * @param null|CarbonInterface $boostEndsAt 加碼結束時刻。加碼關閉、方案不限制，
     *                                          或帳號沒有建立時間時為 null
     * @param bool $boostActive 加碼是否正在生效
     */
    public function __construct(
        public readonly int $limit,
        public readonly int $planLimit,
        public readonly int $used,
        public readonly CarbonInterface $windowStart,
        public readonly int $boostLimit,
        public readonly ?CarbonInterface $boostEndsAt,
        public readonly bool $boostActive,
    ) {
    }

    public function isUnlimited(): bool
    {
        return $this->limit <= 0;
    }

    /**
     * 還能加幾支。不限制時回 null——回 0 會被呼叫端讀成「不能再加」。
     */
    public function remaining(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * 額度是否已經滿了。
     *
     * 用 >= 而不是 >：這是「還能不能再加一支」的判斷，等於上限時就不能再加。
     */
    public function exhausted(): bool
    {
        return !$this->isUnlimited() && $this->used >= $this->limit;
    }

    /**
     * 回給前端的形狀。usage API 與（未來的）其他端點共用，欄位名就只有一處。
     *
     * @return array{active: bool, limit: int, plan_limit: int, ends_at: null|int}
     */
    public function boostPayload(): array
    {
        return [
            'active'     => $this->boostActive,
            'limit'      => $this->boostLimit,
            'plan_limit' => $this->planLimit,
            'ends_at'    => $this->boostEndsAt?->getTimestamp(),
        ];
    }
}
