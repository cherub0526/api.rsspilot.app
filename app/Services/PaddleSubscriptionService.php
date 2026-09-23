<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Paddle;
use App\Models\Transaction;
use App\Models\Subscription;
use Paddle\SDK\Exceptions\ApiError;
use Paddle\SDK\Entities\Shared\TransactionStatus;
use Paddle\SDK\Entities\Subscription\SubscriptionStatus;
use Paddle\SDK\Exceptions\SdkExceptions\MalformedResponse;
use Paddle\SDK\Notifications\Entities\Payout\PayoutStatus;
use Paddle\SDK\Entities\Transaction as PaddleTransactionEntity;
use Paddle\SDK\Entities\Subscription as PaddleSubscriptionEntity;

class PaddleSubscriptionService
{
    /** 什麼都不用做——Paddle 沒把這筆訂閱開成試用。 */
    public const FREE_MONTH_ACTION_NONE = 'none';

    /** 保留 Paddle 的免費月：首次扣款就是一個月後。 */
    public const FREE_MONTH_ACTION_KEEP = 'keep';

    /** 立刻啟用並計費——這個帳號的首月免費已經用掉了。 */
    public const FREE_MONTH_ACTION_ACTIVATE = 'activate';

    public function createCheckout(User $user, Plan $plan, Price $price, Subscription $subscription): array
    {
        $data = [
            'paddle' => [
                'client_token' => env('PADDLE_CLIENT_TOKEN'),
                'environment'  => env('PADDLE_SANDBOX') ? 'sandbox' : 'production',
            ],
            'items'    => [$price->paddle->paddle_id],
            'customer' => [
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'customData' => [
                'subscriptionId' => $subscription->id,
            ],
        ];

        if ($user->paddle) {
            $data['customer']['id'] = $user->paddle->paddle_customer_id;
        }

        return $data;
    }

    public function confirm(Subscription $subscription, string $transactionId): bool
    {
        $paddle = new PaddleClient();

        try {
            $paddleTransaction = $paddle->transactions()->get($transactionId);

            if (
                $paddleTransaction->status->getValue() === PayoutStatus::Paid()->getValue()
                || $paddleTransaction->status->getValue() === TransactionStatus::Completed()->getValue()
            ) {
                $billedAt = Carbon::parse($paddleTransaction->billedAt);
                $items = $paddleTransaction->items;

                // 首次訂閱的結帳金額是 0（price 上帶著一個月的 trial_period），所以
                // 日期不能從這筆交易推算——要問 Paddle 訂閱本身的 next_billed_at。
                if ($paddleTransaction->subscriptionId) {
                    $paddleSubscription = $this->applyFreeMonth(
                        $subscription,
                        $paddle->subscriptions()->get($paddleTransaction->subscriptionId)
                    );

                    $this->syncFromPaddle($subscription, $paddleSubscription);

                    // 這裡一定要記下對應，不能只靠 webhook 補。少了這行，訂閱會變成
                    // active 卻連不到 Paddle——webhook 延遲、失敗，或本機開發根本收
                    // 不到（sandbox 通知目的地不是 localhost）時，使用者之後按取消
                    // 就會找不到要取消哪一筆。rememberPaddleSubscription() 是冪等的，
                    // 跟 webhook 誰先到都不會重複建列。
                    $this->rememberPaddleSubscription($subscription, $paddleSubscription);

                    return true;
                }

                $subscription->fill([
                    'status'     => Subscription::STATUS_ACTIVE,
                    'start_date' => $billedAt->clone()->toDateTime(),
                    'next_date'  => $billedAt->clone()->add(
                        sprintf(
                            '%d %s',
                            $items[0]->price->billingCycle->frequency,
                            $items[0]->price->billingCycle->interval
                        )
                    ),
                ])->save();

                return true;
            }
        } catch (ApiError $e) {
        } catch (MalformedResponse $e) {
        }

        return false;
    }

    /**
     * 決定這筆 Paddle 訂閱能不能留著那個免費月，並回傳處理完後的訂閱。
     *
     * **為什麼需要這一步**：Paddle 的試用期是綁在 *price* 上的固定長度
     * （`trial_period`，由 `paddle:sync` 設成一個月），每個結帳的人都一樣吃得到。
     * 「首月免費只送第一次」這條規則 Paddle 不知道，只能由我們在訂閱建立之後補。
     *
     * 三條路（判斷本身在 `freeMonthAction()`，純函式、可測）：
     *
     * - 訂閱不是 trialing（price 沒設 trial_period）→ 什麼都不做，維持既有行為
     * - 還有資格 → 保留 Paddle 給的免費月，連 API 都不用呼叫。首次扣款日就是
     *   Paddle 自己算好的一個月後
     * - 沒有資格 → `POST /subscriptions/{id}/activate`，當場計費。**這條路不能
     *   省**：price 帶著 trial_period，不處理的話「訂閱 → 取消 → 再訂閱」就能
     *   無限續杯
     */
    public function applyFreeMonth(
        Subscription $subscription,
        PaddleSubscriptionEntity $paddleSubscription
    ): PaddleSubscriptionEntity {
        $action = $this->freeMonthAction(
            (string) $paddleSubscription->status->getValue(),
            (new SubscriptionService())->isEligibleForFreeMonth(
                (string) $subscription->user_id,
                (string) $subscription->getKey()
            )
        );

        if ($action !== self::FREE_MONTH_ACTION_ACTIVATE) {
            return $paddleSubscription;
        }

        $paddle = new PaddleClient();
        $paddle->subscriptions()->activate($paddleSubscription->id);

        return $paddle->subscriptions()->get($paddleSubscription->id);
    }

    /**
     * 要對這筆 Paddle 訂閱做什麼——判斷抽出來是為了能測：Paddle SDK 的 client 是
     * 在各處直接 new 的，沒辦法換成測試替身（見 PaddleControllerTest 的說明）。
     *
     * @param string $paddleStatus Paddle 訂閱的狀態
     * @param bool $eligible 這個帳號還有沒有首月免費的資格
     */
    public function freeMonthAction(string $paddleStatus, bool $eligible): string
    {
        if ($paddleStatus !== SubscriptionStatus::Trialing()->getValue()) {
            return self::FREE_MONTH_ACTION_NONE;
        }

        return $eligible ? self::FREE_MONTH_ACTION_KEEP : self::FREE_MONTH_ACTION_ACTIVATE;
    }

    /**
     * 把 Paddle 的訂閱狀態轉成我們自己的 `status`。
     *
     * 抽成純函式的理由同 `freeMonthAction()`：Paddle SDK 的 client 到處直接 new，
     * 換不掉，所以判斷本身要能單獨測。
     *
     * 對照表與**為什麼**：
     *
     * - `trialing` → `trial`：免費月期間。前端靠這個顯示 Trial 徽章
     * - `active` → `active`
     * - `past_due` → **`active`**（不是取消）。扣款失敗後 Paddle 會進入 dunning，
     *   自動重試兩週左右才放棄。這段期間把人停權是錯的——卡片過期這種小事會
     *   讓還在付錢的客人當場失去服務。真的收不到時 Paddle 會再送一則
     *   `subscription.canceled`，那時才轉 `canceled`。注意 `scopeActive()` 對
     *   `active` 不看 `next_date`，所以 dunning 期間權限自然維持
     * - `canceled` / `inactive` → `canceled`
     * - `paused` → `canceled`：我們自己沒有暫停的流程，但有人從 Dashboard 按下去
     *   時不能把它讀成「還在訂閱」。Paddle 暫停期間不計費，權限就該停；之後
     *   `subscription.resumed` 會把它帶回 `active`
     *
     * 未知狀態一律回 `canceled`：Paddle 之後新增狀態時，寧可少給權限也不要
     * 因為 `default` 落在 `active` 而把不該有權限的人放進來。
     */
    public function statusFor(string $paddleStatus): string
    {
        return match ($paddleStatus) {
            SubscriptionStatus::Trialing()->getValue() => Subscription::STATUS_TRIAL,
            SubscriptionStatus::Active()->getValue(),
            SubscriptionStatus::PastDue()->getValue() => Subscription::STATUS_ACTIVE,
            default                                   => Subscription::STATUS_CANCELED,
        };
    }

    /**
     * 照 Paddle 回報的狀態與日期寫回我們自己的訂閱。
     *
     * 三個欄位的語意：
     *
     * - `status`：免費月期間是 `trial`，首次扣款成功後才轉 `active`。前端靠這個
     *   顯示 Trial 徽章，`GET /v1/subscriptions` 也只在 `trial` 時回
     *   `trial_ends_at`——而那個日子就是第一次扣款的日子
     * - `start_date`：訂閱開始的那天，也就是結帳當天。免費月是這筆訂閱的一部分，
     *   不是它的前傳，所以不再像過去那樣記成「試用結束日」
     * - `next_date`：`next_billed_at`。免費月期間就是首次扣款日；`scopeActive()`
     *   對 `trial` 狀態會比對這個欄位，所以首次扣款沒過（Paddle 轉 past_due、
     *   日期停在過去）的訂閱會自然落回免費方案
     */
    public function syncFromPaddle(Subscription $subscription, PaddleSubscriptionEntity $paddleSubscription): void
    {
        $attributes = [
            'status'     => $this->statusFor((string) $paddleSubscription->status->getValue()),
            'start_date' => Carbon::parse($paddleSubscription->createdAt)->toDateTime(),
        ];

        // 取消或暫停後 next_billed_at 會是 null，這時保留原本的日期——把它寫成
        // null 會讓 scopeActive() 把一筆早就該結束的訂閱當成永遠有效。
        if ($paddleSubscription->nextBilledAt) {
            $attributes['next_date'] = Carbon::parse($paddleSubscription->nextBilledAt)->toDateTime();
        }

        // Paddle 是取消日期的事實來源。只在它有值時寫入，不要因為某一則事件沒帶
        // 就把既有的取消日期抹掉。
        if ($paddleSubscription->canceledAt) {
            $attributes['cancellation_date'] = Carbon::parse($paddleSubscription->canceledAt)->toDateTime();
        }

        $subscription->fill($attributes)->save();
    }

    public function cancel(Subscription $subscription): void
    {
        $paddle = new PaddleClient();
        $paddle->subscriptions()->cancel($subscription->paddle->paddle_id);
    }

    /**
     * `transaction.completed`：把這筆交易與它背後的訂閱寫回我們自己的資料表。
     *
     * 交易實體由呼叫端取好再傳進來——controller 本來就要先拿它才能從
     * `customData.subscriptionId` 找出是哪一筆訂閱，這裡再抓一次是白跑一趟。
     */
    public function handleTransactionCompleted(
        Subscription $subscription,
        PaddleTransactionEntity $paddleTransaction
    ): void {
        $paddleClient = new PaddleClient();

        try {
            $paddleSubscription = $this->applyFreeMonth(
                $subscription,
                $paddleClient->subscriptions()->get($paddleTransaction->subscriptionId)
            );

            $this->syncFromPaddle($subscription, $paddleSubscription);
            $this->rememberPaddleSubscription($subscription, $paddleSubscription);

            $transactionPaddle = $subscription->transactions()->whereHas(
                'paddle',
                fn ($b) => $b->where('paddle_id', $paddleTransaction->id)
            )->first();

            if (!$transactionPaddle) {
                $transactionPaddle = $subscription->transactions()->create([
                    'billing_date' => Carbon::parse($paddleTransaction->billedAt),
                    'amount'       => floatval($paddleTransaction->details->totals->total) / 100,
                    'status'       => TransactionStatus::Completed()->getValue(),
                ]);

                $transactionPaddle->paddle()->create([
                    'paddle_id'     => $paddleTransaction->id,
                    'paddle_detail' => $paddleTransaction,
                    'foreign_type'  => Transaction::class,
                ]);
            }

            $subscription->fill([
                'last_charged_date' => Carbon::parse($paddleTransaction->billedAt)->toDateTime(),
            ])->save();
        } catch (ApiError $e) {
        } catch (MalformedResponse $e) {
        }
    }

    /**
     * 所有 `subscription.*` 事件共用的處理：跟 Paddle 重新對一次答案。
     *
     * **不看 webhook 帶來的 payload，一律回頭跟 Paddle 要現況**，理由是 Paddle
     * 不保證事件的送達順序。照 payload 寫的話，一則晚到的 `subscription.updated`
     * 會把已經寫好的 `canceled` 蓋回 `active`——而且是安靜地蓋掉。改成每次都讀
     * 當下的真實狀態，順序就不再重要，重送也不會有副作用（冪等）。
     *
     * 也因為這樣，這一個方法就足以應付 activated / updated / canceled / past_due /
     * paused / resumed 六種事件，不必一種寫一段。
     *
     * @return bool 成功對完答案才回 true；對不到（Paddle 那邊出錯）回 false，
     *              由呼叫端決定要不要讓 Paddle 重送
     */
    public function handleSubscriptionEvent(Subscription $subscription, string $paddleSubscriptionId): bool
    {
        $paddleClient = new PaddleClient();

        try {
            $paddleSubscription = $paddleClient->subscriptions()->get($paddleSubscriptionId);
        } catch (ApiError $e) {
            return false;
        } catch (MalformedResponse $e) {
            return false;
        }

        $this->syncFromPaddle($subscription, $paddleSubscription);
        $this->rememberPaddleSubscription($subscription, $paddleSubscription);

        return true;
    }

    /**
     * 從事件內容找出這是我們哪一筆訂閱。
     *
     * 兩條路，順序有意義：
     *
     * 1. `paddles` 表以 `paddle_id` 反查——首購走完 `transaction.completed` 之後
     *    就會有這一列，是最可靠的對應
     * 2. 退回結帳時塞進 `customData.subscriptionId` 的值。第一筆 `subscription.*`
     *    事件有可能比 `transaction.completed` 早到，那時第 1 條還查不到東西
     *
     * 兩條都沒中就回 null，讓呼叫端決定怎麼回應。
     */
    public function resolveSubscription(string $paddleSubscriptionId, ?string $customDataSubscriptionId): ?Subscription
    {
        $paddle = Paddle::query()
            ->where('foreign_type', Subscription::class)
            ->where('paddle_id', $paddleSubscriptionId)
            ->first();

        if ($paddle && $subscription = Subscription::query()->find($paddle->foreign_id)) {
            return $subscription;
        }

        if ($customDataSubscriptionId === null || $customDataSubscriptionId === '') {
            return null;
        }

        return Subscription::query()->find($customDataSubscriptionId);
    }

    /**
     * 記住（或更新）這筆訂閱對應的 Paddle 訂閱列。
     *
     * 已經有同一個 `paddle_id` 時只刷新 `paddle_detail`，不要再插一列——
     * `subscription.*` 事件會反覆進來，每次都 create 的話這張表會長滿重複資料，
     * 而 `subscription->paddle()` 是 hasOne，之後讀到哪一列就看運氣。
     */
    private function rememberPaddleSubscription(
        Subscription $subscription,
        PaddleSubscriptionEntity $paddleSubscription
    ): void {
        $existing = $subscription->paddle()->where('paddle_id', $paddleSubscription->id)->first();

        if ($existing) {
            $existing->fill(['paddle_detail' => $paddleSubscription])->save();

            return;
        }

        $subscription->paddle()->create([
            'paddle_id'     => $paddleSubscription->id,
            'paddle_detail' => $paddleSubscription,
            'foreign_type'  => Subscription::class,
        ]);
    }
}
