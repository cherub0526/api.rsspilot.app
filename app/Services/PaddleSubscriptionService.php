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
use Paddle\SDK\Resources\Subscriptions\Operations\UpdateSubscription;
use Paddle\SDK\Entities\Subscription\SubscriptionProrationBillingMode;

class PaddleSubscriptionService
{
    /** 什麼都不用做——這筆 Paddle 訂閱本來就不是試用中。 */
    public const TRIAL_ACTION_NONE = 'none';

    /** 把首次扣款推到我們的試用結束日。 */
    public const TRIAL_ACTION_DEFER = 'defer';

    /** 立刻啟用並計費——沒有剩餘試用可補。 */
    public const TRIAL_ACTION_ACTIVATE = 'activate';
    /**
     * Paddle 對 `next_billed_at` 的硬性規定：**至少要是 30 分鐘之後**。
     * 剩餘試用比這還短就沒得延，只能當場啟用計費。
     */
    private const MIN_NEXT_BILLED_MINUTES = 30;

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

                // 帶著試用結帳時，這筆訂閱真正開始的日子是試用結束那天，不是刷卡
                // 那天。先讓 Paddle 的試用結束日對齊我們的，再照它回報的日期寫入。
                if ($paddleTransaction->subscriptionId) {
                    $aligned = $this->alignTrialBilling(
                        $subscription,
                        $paddle->subscriptions()->get($paddleTransaction->subscriptionId)
                    );

                    $subscription->fill([
                        'status'     => Subscription::STATUS_ACTIVE,
                        'start_date' => $this->startDateFor($aligned)->toDateTime(),
                        'next_date'  => Carbon::parse($aligned->nextBilledAt)->toDateTime(),
                    ])->save();

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
     * 讓 Paddle 那邊的試用結束日對齊我們自己的試用結束日。
     *
     * **為什麼需要這一步**：Paddle 的試用期是設在 *price* 上的固定長度
     * （`trial_period: {interval, frequency}`），沒有像 Stripe `trial_end` 那種
     * 「這一次結帳算到哪一天」的參數。所以流程只能是：價格先帶一段試用讓結帳當下
     * 不扣款，訂閱一建立就把 `next_billed_at` 改成我們真正的試用結束日。
     *
     * 三條路（判斷本身在 `trialAction()`，純函式、可測）：
     *
     * - 這筆訂閱不是 trialing（價格沒設試用期）→ 什麼都不做，行為與過去相同
     * - 還有剩餘試用 → `PATCH /subscriptions/{id}`，`do_not_bill`
     * - 沒有剩餘試用 → `POST /subscriptions/{id}/activate`，當場計費。**這條路不能
     *   省**：價格帶著試用期，不處理的話沒有試用的人也會白拿一段免費期間。
     *
     * 回傳改完之後重新取得的 Paddle 訂閱，呼叫端據此寫入日期。
     */
    public function alignTrialBilling(Subscription $subscription, PaddleSubscriptionEntity $paddleSubscription): PaddleSubscriptionEntity
    {
        $action = $this->trialAction(
            (string) $paddleSubscription->status->getValue(),
            $this->trialEndFor((string) $subscription->user_id)
        );

        if ($action === self::TRIAL_ACTION_NONE) {
            return $paddleSubscription;
        }

        $paddle = new PaddleClient();

        if ($action === self::TRIAL_ACTION_DEFER) {
            $paddle->subscriptions()->update($paddleSubscription->id, new UpdateSubscription(
                nextBilledAt: $this->trialEndFor((string) $subscription->user_id),
                prorationBillingMode: SubscriptionProrationBillingMode::DoNotBill(),
            ));
        } else {
            $paddle->subscriptions()->activate($paddleSubscription->id);
        }

        return $paddle->subscriptions()->get($paddleSubscription->id);
    }

    /**
     * 要對這筆 Paddle 訂閱做什麼——判斷抽出來是為了能測：Paddle SDK 的 client 是
     * 在各處直接 new 的，沒辦法換成測試替身（見 PaddleControllerTest 的說明）。
     *
     * @param string $paddleStatus Paddle 訂閱的狀態
     * @param null|Carbon $trialEnd 我們自己的試用結束日，沒有試用時是 null
     */
    public function trialAction(string $paddleStatus, ?Carbon $trialEnd): string
    {
        if ($paddleStatus !== SubscriptionStatus::Trialing()->getValue()) {
            return self::TRIAL_ACTION_NONE;
        }

        return $trialEnd !== null && $trialEnd->isAfter(now()->addMinutes(self::MIN_NEXT_BILLED_MINUTES))
            ? self::TRIAL_ACTION_DEFER
            : self::TRIAL_ACTION_ACTIVATE;
    }

    /**
     * 這位使用者的試用結束日，沒有可用的試用時回 null。
     */
    public function trialEndFor(string $userId): ?Carbon
    {
        /** @var null|Subscription $trial */
        $trial = Subscription::query()
            ->where('user_id', $userId)
            ->where('status', Subscription::STATUS_TRIAL)
            ->whereNotNull('next_date')
            ->where('next_date', '>', now())
            ->orderByDesc('next_date')
            ->first();

        return $trial?->next_date;
    }

    /**
     * 這筆訂閱真正開始的日子。
     *
     * 還在試用中的話是「首次扣款那天」而不是建立那天——使用者在試用中途結帳時，
     * 訂閱起始日應該是試用結束日，跟帳單同一天。
     */
    public function startDateFor(PaddleSubscriptionEntity $paddleSubscription): Carbon
    {
        $isTrialing = (string) $paddleSubscription->status->getValue() === SubscriptionStatus::Trialing()->getValue();

        return $isTrialing && $paddleSubscription->nextBilledAt
            ? Carbon::parse($paddleSubscription->nextBilledAt)
            : Carbon::parse($paddleSubscription->createdAt);
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

            $paddleSubscription = $this->alignTrialBilling(
                $subscription,
                $paddleClient->subscriptions()->get($paddleTransaction->subscriptionId)
            );

            $subscription->fill([
                'start_date' => $this->startDateFor($paddleSubscription)->toDateTime(),
                'next_date'  => Carbon::parse($paddleSubscription->nextBilledAt)->toDateTime(),
                'status'     => Subscription::STATUS_ACTIVE,
            ])->save();

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
