<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Stripe;
use Stripe\ApiRequestor;
use App\Models\Subscription;
use Tests\Support\FakeStripeHttpClient;
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

    protected function tearDown(): void
    {
        // ApiRequestor 的 http client 是 static，不還原會外洩到後面的測試。
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function makeSubscriptionWithStripe(): array
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create());
        $price = Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $plan->id]));
        $user = User::factory()->create();

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

    /**
     * 帶著試用結帳時，這筆訂閱真正開始的日子是試用結束那天，不是刷卡那天。
     *
     * Stripe 在試用期間的 current_period 指的是「試用這一段」，直接拿
     * current_period_start 會把起始日記成刷卡日，使用者看到的訂閱起始日就會
     * 比帳單早一整個試用期。
     */
    public function testCheckoutCompletedDuringATrialStartsOnTheTrialEnd(): void
    {
        [$subscription, $stripeSubId] = $this->makeSubscriptionWithStripe();

        $subscription->fill(['start_date' => null])->save();

        $trialEnd = now()->addDays(20)->startOfSecond();

        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            "get /v1/subscriptions/{$stripeSubId}" => [
                'id'        => $stripeSubId,
                'object'    => 'subscription',
                'trial_end' => $trialEnd->getTimestamp(),
                'items'     => [
                    'object' => 'list',
                    'data'   => [[
                        'id'                   => 'si_test',
                        'object'               => 'subscription_item',
                        'current_period_start' => now()->getTimestamp(),
                        'current_period_end'   => $trialEnd->getTimestamp(),
                    ]],
                ],
            ],
        ]));

        (new StripeSubscriptionService())->handleCheckoutSessionCompleted([
            'data' => ['object' => [
                'metadata'     => ['subscriptionId' => $subscription->id],
                'subscription' => $stripeSubId,
            ]],
        ]);

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(
            $trialEnd->toDateTimeString(),
            $subscription->start_date->toDateTimeString(),
            '訂閱起始日要是試用結束那天'
        );
        $this->assertSame(
            $trialEnd->toDateTimeString(),
            $subscription->next_date->toDateTimeString(),
            '第一次扣款就在試用結束那天'
        );
    }

    /** 沒有試用時維持原本的行為：起始日就是這一期的開始。 */
    public function testCheckoutCompletedWithoutATrialStartsImmediately(): void
    {
        [$subscription, $stripeSubId] = $this->makeSubscriptionWithStripe();

        $subscription->fill(['start_date' => null])->save();

        $periodStart = now()->startOfSecond();
        $periodEnd = now()->addMonth()->startOfSecond();

        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            "get /v1/subscriptions/{$stripeSubId}" => [
                'id'        => $stripeSubId,
                'object'    => 'subscription',
                'trial_end' => null,
                'items'     => [
                    'object' => 'list',
                    'data'   => [[
                        'id'                   => 'si_test',
                        'object'               => 'subscription_item',
                        'current_period_start' => $periodStart->getTimestamp(),
                        'current_period_end'   => $periodEnd->getTimestamp(),
                    ]],
                ],
            ],
        ]));

        (new StripeSubscriptionService())->handleCheckoutSessionCompleted([
            'data' => ['object' => [
                'metadata'     => ['subscriptionId' => $subscription->id],
                'subscription' => $stripeSubId,
            ]],
        ]);

        $subscription->refresh();

        $this->assertSame($periodStart->toDateTimeString(), $subscription->start_date->toDateTimeString());
        $this->assertSame($periodEnd->toDateTimeString(), $subscription->next_date->toDateTimeString());
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
