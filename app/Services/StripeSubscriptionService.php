<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use RuntimeException;
use App\Models\Stripe;
use App\Models\Transaction;
use App\Models\Subscription;
use App\Exceptions\NotFoundHttpException;

class StripeSubscriptionService
{
    public function createCheckout(User $user, Plan $plan, Price $price, Subscription $subscription): array
    {
        $stripe = new StripeClient();

        $customerId = $this->resolveCustomer($stripe, $user);

        $returnUrl = env('STRIPE_RETURN_URL');
        if (!$returnUrl) {
            throw new RuntimeException('STRIPE_RETURN_URL is not configured.');
        }

        $params = [
            'customer'   => $customerId,
            'ui_mode'    => 'embedded_page',
            'mode'       => 'subscription',
            'line_items' => [['price' => $price->stripe->stripe_id, 'quantity' => 1]],
            'return_url' => $returnUrl . '?session_id={CHECKOUT_SESSION_ID}',
            'metadata'   => ['subscriptionId' => $subscription->id],
        ];

        // 第一次訂閱的話第一個月免費：Stripe 當下仍然收取付款方式、但不扣款，
        // 一個月後才開第一張帳單，付費週期也從那天起算。Paddle 那邊的同一件事是
        // 設在 price 上的 trial_period（見 PaddleSubscriptionService）。
        if ($trialEnd = $this->trialEndFor($user)) {
            $params['subscription_data'] = ['trial_end' => $trialEnd->getTimestamp()];
        }

        $session = $stripe->checkoutSessions()->create($params);

        $subscription->stripe()->create([
            'foreign_type'  => Subscription::class,
            'stripe_id'     => $session->id,
            'stripe_detail' => $session->toArray(),
        ]);

        return [
            'stripe' => [
                'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
                'client_secret'   => $session->client_secret,
            ],
        ];
    }

    /**
     * 這次結帳要不要帶免費月——要的話回傳首次扣款的時間，不用的話回 null。
     *
     * 資格判定是共用的（終生一次，見 `SubscriptionService::isEligibleForFreeMonth()`）。
     * 這裡不用擔心 Stripe「trial_end 必須至少是 48 小時之後」的限制：送的固定是
     * 一個月，永遠過得了那道門檻。
     */
    private function trialEndFor(User $user): ?Carbon
    {
        if (!(new SubscriptionService())->isEligibleForFreeMonth((string) $user->id)) {
            return null;
        }

        return now()->addMonths(Subscription::FREE_MONTHS);
    }

    public function cancel(Subscription $subscription): void
    {
        $stripe = new StripeClient();
        $stripe->subscriptions()->cancel($subscription->stripe->stripe_id);
    }

    public function retrieveCheckoutSession(string $sessionId, string $userId): array
    {
        $stripe = new StripeClient();
        $session = $stripe->checkoutSessions()->retrieve($sessionId, [
            'expand' => ['subscription', 'subscription.latest_invoice'],
        ]);

        // 確認 session 屬於該 user 的訂閱，防止越權查詢
        $subscriptionId = $session->metadata['subscriptionId'] ?? null;
        $subscription = $subscriptionId
            ? Subscription::query()->where('id', $subscriptionId)->where('user_id', $userId)->first()
            : null;

        if (!$subscription) {
            throw new NotFoundHttpException();
        }

        $subscription->load(['plan']);
        $subscription->plan->load([
            'prices' => fn ($q) => $q->where('id', $subscription->price_id),
        ]);

        $billing = null;
        if ($session->status === 'complete' && $session->subscription) {
            $stripeSub = $session->subscription;
            $invoice = $stripeSub->latest_invoice;
            $billing = [
                'period_start' => $stripeSub->items->data[0]->current_period_start
                    ? Carbon::createFromTimestamp($stripeSub->items->data[0]->current_period_start)->toIso8601String()
                    : null,
                'period_end' => $stripeSub->items->data[0]->current_period_end
                    ? Carbon::createFromTimestamp($stripeSub->items->data[0]->current_period_end)->toIso8601String()
                    : null,
                'amount'   => $invoice ? $invoice->amount_paid / 100 : null,
                'currency' => $invoice ? strtoupper($invoice->currency) : null,
            ];
        }

        return [
            'status'         => $session->status,
            'customer_email' => $session->customer_details?->email,
            'plan'           => $subscription->plan,
            'billing'        => $billing,
        ];
    }

    public function handleCheckoutSessionCompleted(array $event): void
    {
        $session = $event['data']['object'];
        $subscriptionId = $session['metadata']['subscriptionId'] ?? null;
        $stripeSubId = $session['subscription'] ?? null;

        if (!$subscriptionId || !$stripeSubId) {
            return;
        }

        if (!$subscription = Subscription::query()->find($subscriptionId)) {
            return;
        }

        $stripe = new StripeClient();
        $stripeSub = $stripe->subscriptions()->retrieve($stripeSubId);

        // 將 stripe 記錄從 Checkout Session ID 更新為 Stripe Subscription ID，
        // 讓後續 invoice.paid / subscription.deleted 事件能正確找到對應訂閱。
        $subscription->stripe()->update([
            'stripe_id'     => $stripeSubId,
            'stripe_detail' => $stripeSub->toArray(),
        ]);

        // 免費月期間記成 trial，首次扣款成功（invoice.paid）後才轉 active，跟
        // Paddle 那條路一致——前端的 Trial 徽章與 trial_ends_at 都讀這個狀態。
        $insertData = [
            'status' => $stripeSub->status === 'trialing'
                ? Subscription::STATUS_TRIAL
                : Subscription::STATUS_ACTIVE,
            'next_date' => Carbon::createFromTimestamp($stripeSub->items->data[0]->current_period_end)->toDateTime(),
        ];

        if (!$subscription->start_date) {
            // 訂閱開始的日子就是結帳這天。免費月是這筆訂閱的第一期（Stripe 在試用
            // 期間的 current_period 就是那一段），不是它的前傳。next_date 則是
            // current_period_end——第一次扣款的日子。
            $insertData['start_date'] = Carbon::createFromTimestamp(
                $stripeSub->items->data[0]->current_period_start
            )->toDateTime();
        }

        $subscription->fill($insertData)->save();
    }

    public function handleInvoicePaid(array $event): void
    {
        $invoice = $event['data']['object'];
        $stripeSubId = $invoice['subscription'] ?? null;
        $subscriptionId = $invoice['metadata']['subscriptionId']
            ?? $this->resolveSubscriptionIdFromStripeId($stripeSubId);

        if (!$subscriptionId) {
            return;
        }

        if (!$subscription = Subscription::query()->find($subscriptionId)) {
            return;
        }

        $stripe = new StripeClient();
        $stripeSub = $stripe->subscriptions()->retrieve($stripeSubId);
        $currentPeriodStart = Carbon::createFromTimestamp($stripeSub->items->data[0]->current_period_start);
        $currentPeriodEnd = Carbon::createFromTimestamp($stripeSub->items->data[0]->current_period_end);

        $insertData = [
            'status'    => Subscription::STATUS_ACTIVE,
            'next_date' => $currentPeriodEnd->toDateTime(),
        ];

        if (!$subscription->start_date) {
            $insertData['start_date'] = $currentPeriodStart->toDateTime();
        }
        $subscription->fill($insertData)->save();

        if (
            !$subscription->transactions()->whereHas(
                'stripe',
                fn ($q) => $q->where('stripe_id', $invoice['id'])
            )->exists()
        ) {
            $transaction = $subscription->transactions()->create([
                'billing_date' => Carbon::createFromTimestamp($invoice['created']),
                'amount'       => $invoice['amount_paid'] / 100,
                'status'       => 'paid',
            ]);

            $transaction->stripe()->create([
                'foreign_type'  => Transaction::class,
                'stripe_id'     => $invoice['id'],
                'stripe_detail' => $invoice,
            ]);
        }
    }

    public function handleSubscriptionDeleted(array $event): void
    {
        $stripeSub = $event['data']['object'];
        $stripeId = $stripeSub['id'];

        $stripeRecord = Stripe::query()
            ->where('stripe_id', $stripeId)
            ->where('foreign_type', Subscription::class)
            ->first();

        if (!$stripeRecord || !$subscription = Subscription::query()->find($stripeRecord->foreign_id)) {
            return;
        }

        $subscription->fill(['status' => Subscription::STATUS_CANCELED])->save();
    }

    public function handleInvoicePaymentFailed(array $event): void
    {
        $invoice = $event['data']['object'];
        $stripeSubId = $invoice['subscription'] ?? null;

        if (!$stripeSubId) {
            return;
        }

        $stripeRecord = Stripe::query()
            ->where('stripe_id', $stripeSubId)
            ->where('foreign_type', Subscription::class)
            ->first();

        if (!$stripeRecord || !$subscription = Subscription::query()->find($stripeRecord->foreign_id)) {
            return;
        }

        $subscription->fill(['status' => Subscription::STATUS_PAYING])->save();
    }

    private function resolveCustomer(StripeClient $stripe, User $user): string
    {
        if ($user->stripe()->exists()) {
            return $user->stripe->stripe_id;
        }

        $customer = $stripe->customers()->create([
            'email' => $user->email,
            'name'  => $user->name,
        ]);

        $user->stripe()->create([
            'foreign_type'  => User::class,
            'stripe_id'     => $customer->id,
            'stripe_detail' => $customer->toArray(),
        ]);

        return $customer->id;
    }

    private function resolveSubscriptionIdFromStripeId(?string $stripeSubId): ?string
    {
        if (!$stripeSubId) {
            return null;
        }

        $record = Stripe::query()
            ->where('stripe_id', $stripeSubId)
            ->where('foreign_type', Subscription::class)
            ->first();

        return $record?->foreign_id;
    }
}
