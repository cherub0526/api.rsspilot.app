<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * 一位使用者在當下這個額度日、某一種 AI 額度的用量狀態。
 *
 * 由 DailyQuotaService 的子類別產出（對話、心智圖各一種），同時餵給
 * X-RateLimit-* header 與 usage API，兩邊看到的數字才不會有兩套算法。
 *
 * 刻意不帶「這是哪一種額度」的欄位：header 名稱與錯誤訊息由各自的
 * 端點決定，這個值物件只負責數字與時間。
 */
final class DailyQuotaSnapshot
{
    /**
     * @param int $limit 當下方案的每日上限，0 表示不限制
     * @param int $used 當日已用次數
     * @param CarbonInterface $resetAt 額度重置時刻（隔日 00:00，額度時區）
     * @param string $quotaDate 這份狀態所屬的額度日（Y-m-d）。退還額度時要用它，
     *                          否則跨過午夜才失敗的串流會退到隔天的額度上
     */
    public function __construct(
        public readonly int $limit,
        public readonly int $used,
        public readonly CarbonInterface $resetAt,
        public readonly string $quotaDate,
    ) {
    }

    public function isUnlimited(): bool
    {
        return $this->limit <= 0;
    }

    /**
     * 剩餘次數。降級後 used 可能已經超過新方案的 limit，這裡收斂到 0，
     * 不讓負數流到 header 或 API 回應上。
     */
    public function remaining(): int
    {
        if ($this->isUnlimited()) {
            return 0;
        }

        return max(0, $this->limit - $this->used);
    }

    public function exceeded(): bool
    {
        return !$this->isUnlimited() && $this->used > $this->limit;
    }

    /**
     * 不限制的方案不帶 header —— 送 Limit: 0 會被讀成「一次都不能用」。
     * 前端收不到這組 header 就代表沒有上限。
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        if ($this->isUnlimited()) {
            return [];
        }

        return [
            'X-RateLimit-Limit'     => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $this->remaining(),
            'X-RateLimit-Reset'     => (string) $this->resetAt->getTimestamp(),
        ];
    }
}
