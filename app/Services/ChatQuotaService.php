<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Carbon\Carbon;
use App\Models\User;
use App\Models\ChatUsage;
use Carbon\CarbonInterface;
use Hypervel\Support\Facades\DB;
use App\Exceptions\ChatQuotaExceededException;

/**
 * 每日 AI 提問額度。
 *
 * 上限來自使用者「當下生效方案」的 plans.chat_limit（0 = 不限制），用量記在
 * chat_usages，key 是 (user_id, quota_date)——不依方案分桶，所以當日升級馬上
 * 就能用新方案的剩餘額度，降級則是當日已用不會被抹掉。
 */
class ChatQuotaService
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    /**
     * 當下的額度狀態，不改變任何用量。供 X-RateLimit-* header 與 usage API 使用。
     */
    public function snapshot(User $user): ChatQuotaSnapshot
    {
        $date = $this->quotaDate();

        $used = (int) (ChatUsage::query()
            ->where('user_id', (string) $user->getKey())
            ->where('quota_date', $date)
            ->value('count') ?? 0);

        return new ChatQuotaSnapshot($this->limitFor($user), $used, $this->resetAt(), $date);
    }

    /**
     * 扣掉一次額度並回傳扣完後的狀態。
     *
     * 判斷與遞增在同一個交易、同一把列鎖裡完成——否則同一位使用者併發送出的請求
     * 會各自讀到還沒遞增的數字，免費方案的 3 次可以被刷成任意多次。
     *
     * @throws ChatQuotaExceededException 當日額度已用盡（此時不會扣點）
     */
    public function consume(User $user): ChatQuotaSnapshot
    {
        $limit = $this->limitFor($user);
        $date = $this->quotaDate();
        $userId = (string) $user->getKey();

        $this->ensureRow($userId, $date);

        $used = DB::transaction(function () use ($userId, $date, $limit): int {
            $usage = $this->lockRow($userId, $date);

            // 超限就在交易裡拋出，遞增連同整個交易一起回滾：被擋下來的請求
            // 不該留下用量，否則使用者每重試一次，usage 顯示的數字就再長一格。
            if ($limit > 0 && $usage->count >= $limit) {
                throw new ChatQuotaExceededException(
                    new ChatQuotaSnapshot($limit, $usage->count, $this->resetAt(), $date)
                );
            }

            // increment() 會一併把記憶體裡的 count 加上去，不要再自己加一次。
            $usage->increment('count');

            return $usage->count;
        });

        return new ChatQuotaSnapshot($limit, $used, $this->resetAt(), $date);
    }

    /**
     * 把 consume() 扣掉的那一次還回去。
     *
     * 要傳入 consume() 當時回傳的 snapshot，不能重新算一次日期：串流可能跨過
     * 午夜才失敗，那時候重算會退到隔天的額度上。
     */
    public function release(User $user, ChatQuotaSnapshot $consumed): void
    {
        $userId = (string) $user->getKey();

        DB::transaction(function () use ($userId, $consumed): void {
            $usage = $this->lockRow($userId, $consumed->quotaDate);

            if ($usage->count <= 0) {
                return;
            }

            $usage->decrement('count');
        });
    }

    private function limitFor(User $user): int
    {
        $plan = $this->subscriptions->getUserSubscriptionPlan(
            $this->subscriptions->getUserSubscription((string) $user->getKey())
        );

        return $plan === null ? 0 : (int) $plan->chat_limit;
    }

    /**
     * 先把當日的資料列生出來，交易裡才有東西可鎖。
     */
    private function ensureRow(string $userId, string $date): void
    {
        $exists = ChatUsage::query()
            ->where('user_id', $userId)
            ->where('quota_date', $date)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            ChatUsage::create([
                'user_id'    => $userId,
                'quota_date' => $date,
                'count'      => 0,
            ]);
        } catch (Throwable) {
            // 同一位使用者的兩個併發請求會同時走到這裡，(user_id, quota_date)
            // 的唯一索引讓其中一個插入失敗；失敗的這個沿用另一個建好的資料列。
        }
    }

    private function lockRow(string $userId, string $date): ChatUsage
    {
        $usage = ChatUsage::query()
            ->where('user_id', $userId)
            ->where('quota_date', $date)
            ->lockForUpdate()
            ->first();

        if ($usage instanceof ChatUsage) {
            return $usage;
        }

        return ChatUsage::create([
            'user_id'    => $userId,
            'quota_date' => $date,
            'count'      => 0,
        ]);
    }

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
