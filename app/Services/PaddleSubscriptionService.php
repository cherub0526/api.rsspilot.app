<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\Subscription;
use Paddle\SDK\Exceptions\ApiError;
use Paddle\SDK\Entities\Shared\TransactionStatus;
use Paddle\SDK\Exceptions\SdkExceptions\MalformedResponse;
use Paddle\SDK\Notifications\Entities\Payout\PayoutStatus;

class PaddleSubscriptionService
{
    public function createCheckout(\App\Models\User $user, Plan $plan, Price $price, Subscription $subscription): array
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
                $items    = $paddleTransaction->items;

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

            $paddleSubscription = $paddleClient->subscriptions()->get($paddleTransaction->subscriptionId);

            $subscription->fill([
                'start_date' => Carbon::parse($paddleSubscription->createdAt)->toDateTime(),
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
