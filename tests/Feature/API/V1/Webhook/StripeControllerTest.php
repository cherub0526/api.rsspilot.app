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
     * 免費月期間：訂閱起始日就是結帳這天，狀態記成 trial，next_date 是第一次
     * 扣款的日子。免費月是這筆訂閱的第一期，不是它的前傳。
     */
    public function testCheckoutCompletedDuringTheFreeMonthStartsToday(): void
    {
        [$subscription, $stripeSubId] = $this->makeSubscriptionWithStripe();

        $subscription->fill(['start_date' => null])->save();

        $startedAt = now()->startOfSecond();
        $firstBilledAt = $startedAt->clone()->addMonth();

        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            "get /v1/subscriptions/{$stripeSubId}" => [
                'id'        => $stripeSubId,
                'object'    => 'subscription',
                'status'    => 'trialing',
                'trial_end' => $firstBilledAt->getTimestamp(),
                'items'     => [
                    'object' => 'list',
                    'data'   => [[
                        'id'                   => 'si_test',
                        'object'               => 'subscription_item',
                        'current_period_start' => $startedAt->getTimestamp(),
                        'current_period_end'   => $firstBilledAt->getTimestamp(),
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

        $this->assertSame(
            Subscription::STATUS_TRIAL,
            $subscription->status,
            '免費月期間記成 trial，首次扣款成功後才轉 active'
        );
        $this->assertSame(
            $startedAt->toDateTimeString(),
            $subscription->start_date->toDateTimeString(),
            '訂閱起始日就是結帳這天'
        );
        $this->assertSame(
            $firstBilledAt->toDateTimeString(),
            $subscription->next_date->toDateTimeString(),
            '第一次扣款在一個月後'
        );
    }

    /** 沒有免費月（已經用掉的人）：當場計費，起始日就是這一期的開始。 */
    public function testCheckoutCompletedWithoutTheFreeMonthStartsImmediately(): void
    {
        [$subscription, $stripeSubId] = $this->makeSubscriptionWithStripe();

        $subscription->fill(['start_date' => null])->save();

        $periodStart = now()->startOfSecond();
        $periodEnd = now()->addMonth()->startOfSecond();

        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            "get /v1/subscriptions/{$stripeSubId}" => [
                'id'        => $stripeSubId,
                'object'    => 'subscription',
                'status'    => 'active',
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
