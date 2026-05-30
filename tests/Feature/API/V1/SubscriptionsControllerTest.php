<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Paddle;
use App\Models\Subscription;
use Paddle\SDK\Entities\Shared\BillingCycle;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Paddle\SDK\Entities\TransactionItem as PaddleTransactionItem;
use Paddle\SDK\Resources\Transactions\Operations\Get\Price as PaddlePrice;

/**
 * @internal
 * @coversNothing
 */
class SubscriptionsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;
    private Price $freeMonthlyPrice;

    private Price $freeAnnuallyPrice;
    private Plan $basicPlan;
    private Price $basicMonthlyPrice;
    private Price $basicAnnuallyPrice;

    public function setUp(): void
    {
        parent::setUp();

        $this->freePlan = Plan::withoutEvents(function () {
            return Plan::factory()->create([
                'title'         => 'Free',
                'channel_limit' => 1,
                'video_limit'   => 5,
            ]);
        });

        Paddle::factory()->create([
            'foreign_type' => Plan::class,
            'foreign_id'   => $this->freePlan->id,
            'paddle_id'    => 'pro_free_plan',
        ]);

        $this->freeMonthlyPrice = Price::withoutEvents(function () {
            return Price::factory()->create([
                'plan_id' => $this->freePlan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 0,
            ]);
        });

        Paddle::factory()->create([
            'foreign_type' => Price::class,
            'foreign_id'   => $this->freeMonthlyPrice->id,
            'paddle_id'    => 'pri_free_monthly',
        ]);

        $this->freeAnnuallyPrice = Price::withoutEvents(function () {
            return Price::factory()->create([
                'plan_id' => $this->freePlan->id,
                'unit'    => Price::UNIT_ANNUALLY,
                'price'   => 0,
            ]);
        });

        // Create a basic plan
        $this->basicPlan = Plan::withoutEvents(function () {
            return Plan::factory()->create(['title' => 'Basic']);
        });

        Paddle::factory()->create([
            'foreign_type' => Plan::class,
            'foreign_id'   => $this->basicPlan->id,
            'paddle_id'    => 'pro_basic_plan',
        ]);

        $this->basicMonthlyPrice = Price::withoutEvents(function () {
            return Price::factory()->create([
                'plan_id' => $this->basicPlan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 1000,
            ]);
        });

        Paddle::factory()->create([
            'foreign_type' => Price::class,
            'foreign_id'   => $this->basicMonthlyPrice->id,
            'paddle_id'    => 'pri_basic_monthly',
        ]);

        $this->basicAnnuallyPrice = Price::withoutEvents(function () {
            return Price::factory()->create([
                'plan_id' => $this->basicPlan->id,
                'unit'    => Price::UNIT_ANNUALLY,
                'price'   => 10000,
            ]);
        });

        Paddle::factory()->create([
            'foreign_type' => Price::class,
            'foreign_id'   => $this->basicAnnuallyPrice->id,
            'paddle_id'    => 'pri_basic_annually',
        ]);
    }

    public function testIndex()
    {
        $uri = route('api.v1.subscriptions.index');

        // Unauthenticated
        $this->json('GET', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // User with no active subscription should get the free plan
        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonPath('id', $this->freePlan->id)
            ->assertJsonPath('prices.0.id', $this->freeMonthlyPrice->id);

        // User with an active subscription
        $subscription = Subscription::factory()->create([
            'user_id'  => $user->id,
            'plan_id'  => $this->basicPlan->id,
            'price_id' => $this->basicMonthlyPrice->id,
            'status'   => Subscription::STATUS_ACTIVE,
        ]);

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonPath('id', $this->basicPlan->id)
            ->assertJsonPath('prices.0.id', $this->basicMonthlyPrice->id);
    }

    public function testStore()
    {
        $uri = route('api.v1.subscriptions.store');

        // Unauthenticated
        $this->json('POST', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // Missing params
        $this->json('POST', $uri)->assertStatus(422)->assertJsonStructure(['messages' => ['planId', 'priceId']]);

        // Invalid planId
        $params = ['planId' => 999, 'priceId' => $this->basicMonthlyPrice->id];
        $this->json('POST', $uri, $params)->assertStatus(422)->assertJsonPath(
            'messages.planId.0',
            __('validators.subscription.planId.string')
        );

        // Invalid priceId
        $params = ['planId' => $this->basicPlan->id, 'priceId' => 999];
        $this->json('POST', $uri, $params)->assertStatus(422)->assertJsonPath(
            'messages.priceId.0',
            __('validators.subscription.priceId.string')
        );

        // Price not in plan
        $params = ['planId' => $this->freePlan->id, 'priceId' => $this->basicMonthlyPrice->id];
        $this->json('POST', $uri, $params)->assertStatus(422)->assertJsonPath(
            'messages.priceId.0',
            __('validators.controllers.subscription.price_not_in_plan')
        );

        // Invalid paymentMethod
        $params = ['planId' => $this->basicPlan->id, 'priceId' => $this->basicMonthlyPrice->id, 'paymentMethod' => 'unknown'];
        $this->json('POST', $uri, $params)->assertStatus(422)->assertJsonPath(
            'messages.paymentMethod.0',
            __('validators.subscription.paymentMethod.in')
        );

        // Valid request with explicit paymentMethod=paddle
        $params = ['planId' => $this->basicPlan->id, 'priceId' => $this->basicMonthlyPrice->id, 'paymentMethod' => 'paddle'];
        $this->json('POST', $uri, $params)
            ->assertStatus(200)
            ->assertJsonStructure([
                'paddle' => ['client_token', 'environment'],
                'items',
                'customer'   => ['name', 'email'],
                'customData' => ['subscriptionId'],
            ])
            ->assertJsonPath('items.0', 'pri_basic_monthly')
            ->assertJsonPath('customer.email', $user->email);

        $this->assertDatabaseHas('subscriptions', [
            'user_id'        => $user->id,
            'plan_id'        => $this->basicPlan->id,
            'price_id'       => $this->basicMonthlyPrice->id,
            'payment_method' => Subscription::PAYMENT_METHOD_PADDLE,
            'status'         => Subscription::STATUS_PAYING,
        ]);
    }

    //    public function testUpdate()
    //    {
    //        /** @var User $user */
    //        $user = $this->fakeLogin();
    //        $subscription = Subscription::factory()->create([
    //            'user_id'  => $user->id,
    //            'plan_id'  => $this->proPlan->id,
    //            'price_id' => $this->proMonthlyPrice->id,
    //            'status'   => Subscription::STATUS_PAYING,
    //        ]);
    //
    //        $uri = route('api.v1.subscriptions.update', ['id' => $subscription->id]);
    //
    //        // Mock PaddleClient
    //        $paddleClientMock = Mockery::mock(PaddleClient::class);
    //        $this->app->instance(PaddleClient::class, $paddleClientMock);
    //
    //        // Mock Paddle Transaction response
    //        $paddleTransaction = new PaddleTransaction(
    //            'txn_123',
    //            'sub_123',
    //            'completed',
    //            'cus_123',
    //            null,
    //            null,
    //            'USD',
    //            [],
    //            [],
    //            new DateTime(),
    //            new DateTime(),
    //            null,
    //            [
    //                new PaddleTransactionItem(
    //                    'txnitm_123',
    //                    '10.00',
    //                    '1',
    //                    false,
    //                    new PaddlePrice(
    //                        'pri_pro_monthly',
    //                        'Pro Plan',
    //                        'Standard',
    //                        new BillingCycle('month', 1),
    //                        new TimePeriod('month', 1),
    //                        'prod_123',
    //                        'active',
    //                        null,
    //                        null,
    //                        null,
    //                        null,
    //                        null
    //                    ),
    //                    [],
    //                    null,
    //                    null,
    //                    null
    //                ),
    //            ],
    //            null,
    //            null,
    //            null,
    //            null,
    //            null,
    //            null,
    //            null,
    //            null,
    //            null
    //        );
    //
    //        $transactionsMock = Mockery::mock();
    //        $transactionsMock->shouldReceive('get')->with('txn_123')->andReturn($paddleTransaction);
    //        $paddleClientMock->shouldReceive('transactions')->andReturn($transactionsMock);
    //
    //        $params = ['transaction_id' => 'txn_123'];
    //        $this->json('PUT', $uri, $params)->assertStatus(200);
    //
    //        $subscription->refresh();
    //        $this->assertEquals(Subscription::STATUS_ACTIVE, $subscription->status);
    //        $this->assertNotNull($subscription->start_date);
    //        $this->assertNotNull($subscription->next_date);
    //    }

    public function testUsage()
    {
        $uri = route('api.v1.subscriptions.usage');

        // Unauthenticated
        $this->json('GET', $uri)->assertStatus(401);

        /** @var User $user */
        $this->fakeLogin();

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJson([
                'data' => [
                    'plan' => [
                        'channels' => 1,
                        'media'    => 5,
                    ],
                    'usage' => [
                        'channels' => 0,
                        'media'    => 0,
                    ],
                ],
            ]);
    }
}
