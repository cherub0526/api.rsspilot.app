<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Paddle;
use App\Models\Stripe;
use Stripe\ApiRequestor;
use App\Models\Subscription;
use Tests\Support\FakeStripeHttpClient;
use Hypervel\Foundation\Testing\RefreshDatabase;

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

        // Stripe 分支讀的是 $price->stripe->stripe_id，跟 paddle 的映射並存。
        Stripe::create([
            'foreign_type'  => Price::class,
            'foreign_id'    => $this->basicMonthlyPrice->id,
            'stripe_id'     => 'price_basic_monthly',
            'stripe_detail' => [],
        ]);
    }

    protected function tearDown(): void
    {
        // ApiRequestor 的 http client 是 static，不還原會外洩到後面的測試。
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function testIndex()
    {
        $uri = route('api.v1.subscriptions.index');

        // Unauthenticated
        $this->json('GET', $uri)->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // User with no active subscription should get the free plan; status null
        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonPath('id', $this->freePlan->id)
            ->assertJsonPath('prices.0.id', $this->freeMonthlyPrice->id)
            ->assertJsonPath('status', null)
            ->assertJsonPath('trial_ends_at', null);

        // User with an active paid subscription: status = 'active'
        $subscription = Subscription::factory()->create([
            'user_id'  => $user->id,
            'plan_id'  => $this->basicPlan->id,
            'price_id' => $this->basicMonthlyPrice->id,
            'status'   => Subscription::STATUS_ACTIVE,
        ]);

        $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonPath('id', $this->basicPlan->id)
            ->assertJsonPath('prices.0.id', $this->basicMonthlyPrice->id)
            ->assertJsonPath('status', Subscription::STATUS_ACTIVE)
            ->assertJsonPath('trial_ends_at', null);

        // Expire the active subscription and create a trial: status = 'trial' + trial_ends_at set
        $subscription->update(['status' => Subscription::STATUS_CANCELED]);

        $trialEndsAt = now()->addDays(14);
        Subscription::factory()->create([
            'user_id'    => $user->id,
            'plan_id'    => $this->basicPlan->id,
            'price_id'   => $this->basicMonthlyPrice->id,
            'status'     => Subscription::STATUS_TRIAL,
            'start_date' => now(),
            'next_date'  => $trialEndsAt,
        ]);

        $trialResponse = $this->json('GET', $uri)
            ->assertStatus(200)
            ->assertJsonPath('status', Subscription::STATUS_TRIAL)
            ->assertJsonStructure(['trial_ends_at']);

        $this->assertNotNull($trialResponse->json('trial_ends_at'));
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

    /**
     * 沒帶 paymentMethod 時要落在 Paddle——這是 store() 的預設值，
     * 改回 Stripe 的話前端拿到的 payload 形狀會整個換掉。
     */
    public function testStoreDefaultsToPaddle()
    {
        /** @var User $user */
        $user = $this->fakeLogin();

        $this->json('POST', route('api.v1.subscriptions.store'), [
            'planId'  => $this->basicPlan->id,
            'priceId' => $this->basicMonthlyPrice->id,
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['paddle' => ['client_token', 'environment'], 'items'])
            ->assertJsonPath('items.0', 'pri_basic_monthly');

        $this->assertDatabaseHas('subscriptions', [
            'user_id'        => $user->id,
            'payment_method' => Subscription::PAYMENT_METHOD_PADDLE,
            'status'         => Subscription::STATUS_PAYING,
        ]);
    }

    /**
     * paymentMethod=stripe 要走完 StripeSubscriptionService::createCheckout()：
     * 建 Stripe customer、開 Checkout Session、把 session id 記進 stripes，
     * 回傳前端 initEmbeddedCheckout() 需要的 publishable_key + client_secret。
     */
    public function testStoreCreatesStripeCheckout()
    {
        $http = $this->fakeStripeHttp();

        /** @var User $user */
        $user = $this->fakeLogin();

        $this->json('POST', route('api.v1.subscriptions.store'), [
            'planId'        => $this->basicPlan->id,
            'priceId'       => $this->basicMonthlyPrice->id,
            'paymentMethod' => 'stripe',
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['stripe' => ['publishable_key', 'client_secret']])
            ->assertJsonPath('stripe.publishable_key', 'pk_test_fake_for_tests_only')
            ->assertJsonPath('stripe.client_secret', 'cs_test_session_secret_abc');

        $subscription = Subscription::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(Subscription::PAYMENT_METHOD_STRIPE, $subscription->payment_method);
        $this->assertSame(Subscription::STATUS_PAYING, $subscription->status);

        // 使用者第一次結帳：先建 customer 並存下映射，之後才開 session。
        $customerRequests = $http->requestsFor('post', '/v1/customers');
        $this->assertCount(1, $customerRequests);
        $this->assertSame($user->email, $customerRequests[0]['params']['email']);

        $this->assertDatabaseHas('stripes', [
            'foreign_type' => User::class,
            'foreign_id'   => $user->id,
            'stripe_id'    => 'cus_test_123',
        ]);

        // Checkout Session 的參數決定結帳頁長什麼樣，逐項驗。
        $sessionRequests = $http->requestsFor('post', '/v1/checkout/sessions');
        $this->assertCount(1, $sessionRequests);

        $params = $sessionRequests[0]['params'];
        $this->assertSame('cus_test_123', $params['customer']);
        $this->assertSame('embedded_page', $params['ui_mode']);
        $this->assertSame('subscription', $params['mode']);
        $this->assertSame('price_basic_monthly', $params['line_items'][0]['price']);
        $this->assertSame(1, $params['line_items'][0]['quantity']);
        $this->assertSame(
            'https://tests.invalid/billing/return?session_id={CHECKOUT_SESSION_ID}',
            $params['return_url']
        );
        // webhook 靠這個 metadata 把 Stripe session 對回我們的訂閱。
        $this->assertSame($subscription->id, $params['metadata']['subscriptionId']);

        // session id 要落進 stripes，checkout.session.completed 才找得到訂閱。
        $this->assertDatabaseHas('stripes', [
            'foreign_type' => Subscription::class,
            'foreign_id'   => $subscription->id,
            'stripe_id'    => 'cs_test_session',
        ]);
    }

    /**
     * 已經有 customer 映射的使用者不該再建一次 customer——重複建會讓
     * 同一個人在 Stripe 上散成多個 customer，訂閱與發票就對不起來。
     */
    public function testStoreReusesExistingStripeCustomer()
    {
        $http = $this->fakeStripeHttp();

        /** @var User $user */
        $user = $this->fakeLogin();

        Stripe::create([
            'foreign_type'  => User::class,
            'foreign_id'    => $user->id,
            'stripe_id'     => 'cus_existing_456',
            'stripe_detail' => [],
        ]);

        $this->json('POST', route('api.v1.subscriptions.store'), [
            'planId'        => $this->basicPlan->id,
            'priceId'       => $this->basicMonthlyPrice->id,
            'paymentMethod' => 'stripe',
        ])->assertStatus(200);

        $this->assertCount(0, $http->requestsFor('post', '/v1/customers'));

        $sessionRequests = $http->requestsFor('post', '/v1/checkout/sessions');
        $this->assertCount(1, $sessionRequests);
        $this->assertSame('cus_existing_456', $sessionRequests[0]['params']['customer']);
    }

    /**
     * 把 Stripe SDK 的 HTTP 層換成假的，回傳的 fake 可用來驗發出去的請求。
     */
    private function fakeStripeHttp(): FakeStripeHttpClient
    {
        $http = new FakeStripeHttpClient([
            'post /v1/customers' => [
                'id'     => 'cus_test_123',
                'object' => 'customer',
            ],
            'post /v1/checkout/sessions' => [
                'id'            => 'cs_test_session',
                'object'        => 'checkout.session',
                'client_secret' => 'cs_test_session_secret_abc',
                'status'        => 'open',
                'ui_mode'       => 'embedded_page',
            ],
        ]);

        ApiRequestor::setHttpClient($http);

        return $http;
    }

    /**
     * Only the reachable-without-a-live-Paddle-call surface is covered here.
     * PaddleSubscriptionService::confirm() always constructs a real
     * Paddle\SDK\Client (`new PaddleClient()`, not container-resolved), so a
     * genuine "confirmed" success path can't be exercised without a real
     * network call to Paddle. Same constraint as PaddleControllerTest.
     */
    public function testUpdate()
    {
        // Unauthenticated
        $this->json('PUT', route('api.v1.subscriptions.update', ['subscriptionId' => 'nonexistent']))
            ->assertStatus(401);

        /** @var User $user */
        $user = $this->fakeLogin();

        // Subscription not found / not owned by the current user
        $this->json('PUT', route('api.v1.subscriptions.update', ['subscriptionId' => 'nonexistent']), ['transaction_id' => 'txn_123'])
            ->assertStatus(422)
            ->assertJsonPath('messages.subscriptionId.0', __('validators.controllers.subscription.not_found'));

        $otherUser = User::factory()->create();
        $subscription = Subscription::factory()->create([
            'user_id'  => $otherUser->id,
            'plan_id'  => $this->basicPlan->id,
            'price_id' => $this->basicMonthlyPrice->id,
            'status'   => Subscription::STATUS_PAYING,
        ]);

        $this->json('PUT', route('api.v1.subscriptions.update', ['subscriptionId' => $subscription->id]), ['transaction_id' => 'txn_123'])
            ->assertStatus(422)
            ->assertJsonPath('messages.subscriptionId.0', __('validators.controllers.subscription.not_found'));
    }

    /**
     * Only the reachable-without-a-live-payment-gateway-call surface is
     * covered here. StripeSubscriptionService::cancel() and
     * PaddleSubscriptionService::cancel() both always construct a real SDK
     * client, so a genuine cancellation success path can't be exercised
     * without a real network call.
     */
    public function testDestroy()
    {
        $uri = route('api.v1.subscriptions.destroy', ['subscriptionId' => 'nonexistent']);

        // Unauthenticated
        $this->json('DELETE', $uri)->assertStatus(401);

        // No active subscription (defaults to the free plan) → 404
        $this->fakeLogin();
        $this->json('DELETE', $uri)->assertStatus(404);
    }

    /**
     * Only the reachable-without-a-live-Stripe-call surface is covered here.
     * StripeSubscriptionService::retrieveCheckoutSession() always constructs
     * a real Stripe client, so the "complete" success path can't be
     * exercised without a real network call.
     */
    public function testCheckoutSession()
    {
        $uri = route('api.v1.subscriptions.checkout-session.index');

        // Unauthenticated
        $this->json('GET', $uri)->assertStatus(401);

        $this->fakeLogin();

        // Missing session_id
        $this->json('GET', $uri)
            ->assertStatus(422)
            ->assertJsonPath('messages.session_id.0', __('validators.controllers.subscription.session_id_required'));
    }

    public function testUsage()
    {
        $uri = route('api.v1.subscriptions.usage.index');

        // Unauthenticated
        $this->json('GET', $uri)->assertStatus(401);

        /** @var User $user */
        $this->fakeLogin();

        $this->json('GET', $uri)
            ->assertStatus(200)
            // chat 三個欄位一起斷言：它們讀的是 chat_usages，漏掉的話少跑一支
            // migration 就會讓整個端點 500，而測試仍然是綠的。
            ->assertJsonStructure([
                'data' => [
                    'plan'  => ['channels', 'media', 'chat'],
                    'usage' => ['channels', 'media', 'chat'],
                    'chat_reset_at',
                ],
            ])
            ->assertJson([
                'data' => [
                    'plan' => [
                        'channels' => 1,
                        'media'    => 5,
                    ],
                    'usage' => [
                        'channels' => 0,
                        'media'    => 0,
                        'chat'     => 0,
                    ],
                ],
            ]);
    }
}
