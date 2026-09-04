<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Throwable;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Model;
use Carbon\CarbonInterface;
use Hypervel\Support\Facades\DB;
use Hyperf\Database\Model\Builder;

/**
 * 每日 AI 額度的共用機制。
 *
 * 對話與心智圖各有自己的上限欄位與用量表，但「怎麼算一天、怎麼在併發下安全
 * 扣點、失敗怎麼退還、header 長什麼樣」必須完全一致——否則前端得為每種額度
 * 寫一套 429 處理，而重置時間的算法一旦漂移就是使用者看到的數字對不起來。
 *
 * 子類別只需要回答三件事：用量記在哪張表、上限讀方案的哪個欄位、超限要丟哪個
 * 例外。
 *
 * 用量 key 是 (user_id, quota_date)，不依方案分桶：當日升級馬上就能用新方案的
 * 剩餘額度，降級則是當日已用不會被抹掉。
 */
abstract class DailyQuotaService
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    /**
     * 用量表的 Model 類別，需有 (user_id, quota_date) 唯一索引與 count 欄位。
     *
     * @return class-string<Model>
     */
    abstract protected function usageModelClass(): string;

    /**
     * `plans` 上記錄每日上限的欄位名，0 表示不限制。
     */
    abstract protected function planLimitColumn(): string;

    /**
     * 超限時要拋出的例外。各端點的 429 訊息不同，所以由子類別決定。
     */
    abstract protected function exceededException(DailyQuotaSnapshot $snapshot): Exception;

    /**
     * 當下的額度狀態，不改變任何用量。供 X-RateLimit-* header 與 usage API 使用。
     */
    public function snapshot(User $user): DailyQuotaSnapshot
    {
        $date = $this->quotaDate();

        $used = (int) ($this->usageQuery()
            ->where('user_id', (string) $user->getKey())
            ->where('quota_date', $date)
            ->value('count') ?? 0);

        return new DailyQuotaSnapshot($this->limitFor($user), $used, $this->resetAt(), $date);
    }

    /**
     * 扣掉一次額度並回傳扣完後的狀態。
     *
     * 判斷與遞增在同一個交易、同一把列鎖裡完成——否則同一位使用者併發送出的請求
     * 會各自讀到還沒遞增的數字，免費方案的次數可以被刷成任意多次。
     *
     * @throws Exception 當日額度已用盡（此時不會扣點），實際型別由子類別決定
     */
    public function consume(User $user): DailyQuotaSnapshot
    {
        $limit = $this->limitFor($user);
        $date = $this->quotaDate();
        $userId = (string) $user->getKey();

        $this->ensureRow($userId, $date);

        $used = DB::transaction(function () use ($userId, $date, $limit): int {
            $usage = $this->lockRow($userId, $date);

            // 超限就在交易裡拋出，遞增連同整個交易一起回滾：被擋下來的請求
            // 不該留下用量，否則使用者每重試一次，usage 顯示的數字就再長一格。
            if ($limit > 0 && (int) $usage->getAttribute('count') >= $limit) {
                throw $this->exceededException(
                    new DailyQuotaSnapshot($limit, (int) $usage->getAttribute('count'), $this->resetAt(), $date)
                );
            }

            // increment() 會一併把記憶體裡的 count 加上去，不要再自己加一次。
            $usage->increment('count');

            return (int) $usage->getAttribute('count');
        });

        return new DailyQuotaSnapshot($limit, $used, $this->resetAt(), $date);
    }

    /**
     * 把 consume() 扣掉的那一次還回去。
     *
     * 要傳入 consume() 當時回傳的 snapshot，不能重新算一次日期：串流可能跨過
     * 午夜才失敗，那時候重算會退到隔天的額度上。
     */
    public function release(User $user, DailyQuotaSnapshot $consumed): void
    {
        $userId = (string) $user->getKey();

        DB::transaction(function () use ($userId, $consumed): void {
            $usage = $this->lockRow($userId, $consumed->quotaDate);

            if ((int) $usage->getAttribute('count') <= 0) {
                return;
            }

            $usage->decrement('count');
        });
    }

    protected function limitFor(User $user): int
    {
        $plan = $this->subscriptions->getUserSubscriptionPlan(
            $this->subscriptions->getUserSubscription((string) $user->getKey())
        );

        return $plan === null ? 0 : (int) $plan->{$this->planLimitColumn()};
    }

    /**
     * @return Builder<Model>
     */
    private function usageQuery()
    {
        return ($this->usageModelClass())::query();
    }

    /**
     * 先把當日的資料列生出來，交易裡才有東西可鎖。
     */
    private function ensureRow(string $userId, string $date): void
    {
        $exists = $this->usageQuery()
            ->where('user_id', $userId)
            ->where('quota_date', $date)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            $this->createRow($userId, $date);
        } catch (Throwable) {
            // 同一位使用者的兩個併發請求會同時走到這裡，(user_id, quota_date)
            // 的唯一索引讓其中一個插入失敗；失敗的這個沿用另一個建好的資料列。
        }
    }

    private function lockRow(string $userId, string $date): Model
    {
        $usage = $this->usageQuery()
            ->where('user_id', $userId)
            ->where('quota_date', $date)
            ->lockForUpdate()
            ->first();

        if ($usage instanceof Model) {
            return $usage;
        }

        return $this->createRow($userId, $date);
    }

    private function createRow(string $userId, string $date): Model
    {
        return ($this->usageModelClass())::create([
            'user_id'    => $userId,
            'quota_date' => $date,
            'count'      => 0,
        ]);
    }

    /**
     * 額度日界的時區。所有 AI 額度共用同一個值——對話今天重置、心智圖明天重置
     * 對使用者是無法解釋的。設定鍵沿用 ai.chat.quota_timezone，不改名是為了不動
     * 到既有環境的 AI_CHAT_QUOTA_TIMEZONE。
     */
    private function timezone(): string
    {
        $timezone = config('ai.chat.quota_timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    private function quotaDate(): string
    {
        return Carbon::now($this->timezone())->toDateString();
    }

    private function resetAt(): CarbonInterface
    {
        return Carbon::now($this->timezone())->addDay()->startOfDay();
    }
}
