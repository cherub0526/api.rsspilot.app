<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\Subscription;
use Paddle\SDK\Exceptions\ApiError;
use Paddle\SDK\Entities\Shared\TransactionStatus;
use Paddle\SDK\Entities\Subscription\SubscriptionStatus;
use Paddle\SDK\Exceptions\SdkExceptions\MalformedResponse;
use Paddle\SDK\Notifications\Entities\Payout\PayoutStatus;
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
                    $this->syncFromPaddle(
                        $subscription,
                        $this->applyFreeMonth(
                            $subscription,
                            $paddle->subscriptions()->get($paddleTransaction->subscriptionId)
                        )
                    );

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
        $isTrialing = (string) $paddleSubscription->status->getValue()
            === SubscriptionStatus::Trialing()->getValue();

        $attributes = [
            'status'     => $isTrialing ? Subscription::STATUS_TRIAL : Subscription::STATUS_ACTIVE,
            'start_date' => Carbon::parse($paddleSubscription->createdAt)->toDateTime(),
        ];

        // 取消或暫停後 next_billed_at 會是 null，這時保留原本的日期——把它寫成
        // null 會讓 scopeActive() 把一筆早就該結束的訂閱當成永遠有效。
        if ($paddleSubscription->nextBilledAt) {
            $attributes['next_date'] = Carbon::parse($paddleSubscription->nextBilledAt)->toDateTime();
        }

        $subscription->fill($attributes)->save();
    }

    public function cancel(Subscription $subscription): void
    {
        $paddle = new PaddleClient();
        $paddle->subscriptions()->cancel($subscription->paddle->paddle_id);
    }

    public function handleTransactionCompleted(Subscription $subscription, string $paddleTransactionId): void
    {
        $paddleClient = new PaddleClient();

        try {
            $paddleTransaction = $paddleClient->transactions()->get($paddleTransactionId);

            if ($paddleTransaction->status->getValue() !== TransactionStatus::Completed()->getValue()) {
                return;
            }

            $paddleSubscription = $this->applyFreeMonth(
                $subscription,
                $paddleClient->subscriptions()->get($paddleTransaction->subscriptionId)
            );

            $this->syncFromPaddle($subscription, $paddleSubscription);

            if (!$subscription->paddle()->where(['paddle_id' => $paddleTransaction->subscriptionId])->first()) {
                $subscription->paddle()->create([
                    'paddle_id'     => $paddleSubscription->id,
                    'paddle_detail' => $paddleSubscription,
                    'foreign_type'  => Subscription::class,
                ]);
            }

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
        } catch (ApiError $e) {
        } catch (MalformedResponse $e) {
        }
    }
}
