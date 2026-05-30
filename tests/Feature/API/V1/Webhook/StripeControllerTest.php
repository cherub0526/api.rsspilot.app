<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Stripe;
use App\Models\Subscription;
use App\Services\StripeSubscriptionService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class StripeControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $uri;

    public function setUp(): void
    {
        parent::setUp();
        $this->uri = route('api.v1.webhook.stripe.store');
    }

    private function makeSubscriptionWithStripe(): array
    {
        $plan  = Plan::withoutEvents(fn () => Plan::factory()->create());
        $price = Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $plan->id]));
        $user  = \App\Models\User::factory()->create();

        $subscription = Subscription::factory()->create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => Subscription::PAYMENT_METHOD_STRIPE,
            'status'         => Subscription::STATUS_PAYING,
        ]);

        $stripeSubId = 'sub_test_' . uniqid();

        Stripe::create([
            'foreign_type'  => Subscription::class,
            'foreign_id'    => $subscription->id,
            'stripe_id'     => $stripeSubId,
            'stripe_detail' => [],
        ]);

        return [$subscription, $stripeSubId];
    }

    // --- Controller tests (HTTP layer) ---

    public function testInvalidSignatureReturns422()
    {
        $this->withHeaders(['Stripe-Signature' => 'invalid_signature'])
            ->json('POST', $this->uri, ['type' => 'invoice.paid', 'data' => ['object' => []]])
            ->assertStatus(422);
    }

    public function testMissingSignatureReturns422()
    {
        $this->json('POST', $this->uri, ['type' => 'invoice.paid', 'data' => ['object' => []]])
            ->assertStatus(422);
    }

    // --- Service unit tests (business logic, bypasses HTTP signature check) ---

    public function testHandleSubscriptionDeletedCancelsSubscription()
    {
        [$subscription, $stripeSubId] = $this->makeSubscriptionWithStripe();

        $event = [
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => ['id' => $stripeSubId],
            ],
        ];

        (new StripeSubscriptionService())->handleSubscriptionDeleted($event);

        $this->assertDatabaseHas('subscriptions', [
            'id'     => $subscription->id,
            'status' => Subscription::STATUS_CANCELED,
        ]);
    }

    public function testHandleInvoicePaymentFailedSetsPayingStatus()
    {
        [$subscription, $stripeSubId] = $this->makeSubscriptionWithStripe();
        $subscription->update(['status' => Subscription::STATUS_ACTIVE]);

        $event = [
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'id'           => 'in_failed_' . uniqid(),
                    'subscription' => $stripeSubId,
                ],
            ],
        ];

        (new StripeSubscriptionService())->handleInvoicePaymentFailed($event);

        $this->assertDatabaseHas('subscriptions', [
            'id'     => $subscription->id,
            'status' => Subscription::STATUS_PAYING,
        ]);
    }

    public function testHandleSubscriptionDeletedWithUnknownStripeIdDoesNothing()
    {
        $event = [
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => ['id' => 'sub_nonexistent'],
            ],
        ];

        // Should not throw
        (new StripeSubscriptionService())->handleSubscriptionDeleted($event);

        $this->assertTrue(true);
    }
}
