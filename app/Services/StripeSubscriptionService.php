<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Transaction;
use Exception;

class StripeSubscriptionService
{
    public function createCheckout(User $user, Plan $plan, Price $price, Subscription $subscription): array
    {
        $stripe = new StripeClient();

        $customerId = $this->resolveCustomer($stripe, $user);

        $stripeSubscription = $stripe->subscriptions()->create([
            'customer'         => $customerId,
            'items'            => [['price' => $price->stripe->stripe_id]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand'           => ['latest_invoice.payment_intent'],
            'metadata'         => ['subscriptionId' => $subscription->id],
        ]);

        $subscription->stripe()->create([
            'foreign_type'  => Subscription::class,
            'stripe_id'     => $stripeSubscription->id,
            'stripe_detail' => $stripeSubscription->toArray(),
        ]);

        return [
            'stripe' => [
                'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
                'client_secret'   => $stripeSubscription->latest_invoice->payment_intent->client_secret,
            ],
        ];
    }

    public function cancel(Subscription $subscription): void
    {
        $stripe = new StripeClient();
        $stripe->subscriptions()->cancel($subscription->stripe->stripe_id);
    }

    public function handleInvoicePaid(array $event): void
    {
        $invoice          = $event['data']['object'];
        $stripeSubId      = $invoice['subscription'] ?? null;
        $subscriptionId   = $invoice['metadata']['subscriptionId']
            ?? $this->resolveSubscriptionIdFromStripeId($stripeSubId);

        if (!$subscriptionId) {
            return;
        }

        if (!$subscription = Subscription::query()->find($subscriptionId)) {
            return;
        }

        $stripe            = new StripeClient();
        $stripeSub         = $stripe->subscriptions()->retrieve($stripeSubId);
        $currentPeriodEnd  = Carbon::createFromTimestamp($stripeSub->current_period_end);

        $subscription->fill([
            'status'    => Subscription::STATUS_ACTIVE,
            'next_date' => $currentPeriodEnd->toDateTime(),
        ])->save();

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
        $stripeId  = $stripeSub['id'];

        $stripeRecord = \App\Models\Stripe::query()
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
        $invoice     = $event['data']['object'];
        $stripeSubId = $invoice['subscription'] ?? null;

        if (!$stripeSubId) {
            return;
        }

        $stripeRecord = \App\Models\Stripe::query()
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

        $record = \App\Models\Stripe::query()
            ->where('stripe_id', $stripeSubId)
            ->where('foreign_type', Subscription::class)
            ->first();

        return $record?->foreign_id;
    }
}
