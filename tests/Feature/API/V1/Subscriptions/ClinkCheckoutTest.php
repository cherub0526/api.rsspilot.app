<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Subscriptions;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Clink;
use App\Models\Price;
use App\Models\Subscription;
use Hypervel\Support\Facades\Http;
use Hypervel\HttpClient\Request as HttpRequest;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * `POST /v1/subscriptions` 走 Clink 的那條路。Clink API 一律由 Http::fake 攔下。
 *
 * @internal
 * @coversNothing
 */
class ClinkCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Price $monthly;

    public function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::withoutEvents(fn () => Plan::factory()->create(['title' => 'Pro']));
        $this->monthly = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => '12.99',
        ]));

        Clink::create([
            'foreign_type' => Plan::class,
            'foreign_id'   => $this->plan->id,
            'clink_id'     => 'prd_pro',
            'variant'      => Clink::VARIANT_STANDARD,
        ]);

        foreach ([Clink::VARIANT_STANDARD, Clink::VARIANT_TRIAL] as $variant) {
            Clink::create([
                'foreign_type' => Price::class,
                'foreign_id'   => $this->monthly->id,
                'clink_id'     => "price_pro_monthly_{$variant}",
                'variant'      => $variant,
            ]);
        }
    }

    public function testStoreCreatesAClinkCheckoutWithTheTrialPriceForAFirstSubscription(): void
    {
        $this->fakeSession();
        $user = $this->fakeLogin();

        $response = $this->checkout(['paymentMethod' => 'clink'])
            ->assertStatus(200)
            ->assertJsonPath('clink.checkout_url', 'https://uat-checkout.clinkbill.com/sess_new')
            ->assertJsonPath('clink.session_id', 'sess_new')
            ->assertJsonPath('clink.test_mode', true);

        /** @var Subscription $subscription */
        $subscription = Subscription::query()->where('user_id', $user->id)->firstOrFail();

        $response->assertJsonPath('customData.subscriptionId', $subscription->id);
        $this->assertSame(Subscription::PAYMENT_METHOD_CLINK, $subscription->payment_method);
        $this->assertSame(Subscription::STATUS_PAYING, $subscription->status);

        $sent = $this->sentSessionBody();
        $this->assertSame('price_pro_monthly_trial', $sent['priceId']);
        $this->assertSame('prd_pro', $sent['productId']);
        $this->assertSame((string) $user->id, $sent['referenceCustomerId']);
        $this->assertSame($user->email, $sent['customerEmail']);
        $this->assertSame($subscription->id, $sent['merchantReferenceId']);
        $this->assertSame(['subscriptionId' => $subscription->id], $sent['metadata']);
        $this->assertSame('USD', $sent['originalCurrency']);
        $this->assertSame('hostedPage', $sent['uiMode']);
        $this->assertSame('https://tests.invalid/checkout/return', $sent['successUrl']);
        $this->assertSame('https://tests.invalid/pricing', $sent['cancelUrl']);

        $this->assertDatabaseHas('clinks', [
            'foreign_type' => Subscription::class,
            'foreign_id'   => $subscription->id,
            'clink_id'     => 'sess_new',
            'variant'      => Clink::VARIANT_TRIAL,
        ]);
    }

    /** Clink 的金額是主幣別：US$12.99 送 12.99，送 1299 會收 US$1299。 */
    public function testStoreSendsTheAmountInMajorUnits(): void
    {
        $this->fakeSession();
        $this->fakeLogin();

        $this->checkout(['paymentMethod' => 'clink'])->assertStatus(200);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), '"originalAmount":12.99'));
    }

    public function testStoreUsesTheStandardPriceWhenTheAccountAlreadyUsedTheOffer(): void
    {
        $this->fakeSession();
        $user = $this->fakeLogin();
        $this->pastSubscription($user);

        $this->checkout(['paymentMethod' => 'clink'])->assertStatus(200);

        $this->assertSame('price_pro_monthly_standard', $this->sentSessionBody()['priceId']);
        $this->assertDatabaseHas('clinks', ['clink_id' => 'sess_new', 'variant' => Clink::VARIANT_STANDARD]);
    }

    /** 缺 trial 對應時結帳失敗，不能退回 standard 而當場收有資格者的錢。 */
    public function testStoreFailsInsteadOfFallingBackToTheOtherVariant(): void
    {
        $this->fakeSession();
        $this->fakeLogin();
        Clink::query()->where('clink_id', 'price_pro_monthly_trial')->delete();

        $this->checkout(['paymentMethod' => 'clink'])->assertStatus(500);

        Http::assertNothingSent();
    }

    /**
     * 首期折價在 Clink 還沒實作。設了 first_period_price 的 price 寧可結帳失敗，
     * 也不要定價頁寫著折價、結帳卻收原價。
     */
    public function testStoreFailsClosedWhenThePriceHasAFirstPeriodDiscount(): void
    {
        $annual = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id'            => $this->plan->id,
            'unit'               => Price::UNIT_ANNUALLY,
            'price'              => '120.00',
            'first_period_price' => '99.00',
        ]));

        foreach ([Clink::VARIANT_STANDARD, Clink::VARIANT_TRIAL] as $variant) {
            Clink::create([
                'foreign_type' => Price::class,
                'foreign_id'   => $annual->id,
                'clink_id'     => "price_pro_annual_{$variant}",
                'variant'      => $variant,
            ]);
        }

        $this->fakeSession();
        $this->fakeLogin();

        $this->checkout(['priceId' => $annual->id, 'paymentMethod' => 'clink'])->assertStatus(500);

        Http::assertNothingSent();
    }

    /** phpunit.xml.dist 把 PAYMENT_DEFAULT_PROVIDER 釘成正式環境的 clink。 */
    public function testStoreDefaultsToClink(): void
    {
        $this->fakeSession();
        $user = $this->fakeLogin();

        $this->checkout()->assertStatus(200)->assertJsonPath('clink.session_id', 'sess_new');

        $this->assertDatabaseHas('subscriptions', [
            'user_id'        => $user->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CLINK,
        ]);
    }

    /** 認不得的值（打錯字、已移除的 paddle）退回 clink，不讓整站結不了帳。 */
    public function testDefaultPaymentMethodFallsBackToClink(): void
    {
        foreach (['paddle', 'typo', ''] as $value) {
            $this->withDefaultProvider($value, function () use ($value) {
                $this->assertSame(
                    Subscription::PAYMENT_METHOD_CLINK,
                    Subscription::defaultPaymentMethod(),
                    "PAYMENT_DEFAULT_PROVIDER={$value}"
                );
            });
        }

        $this->withDefaultProvider('creem', function () {
            $this->assertSame(Subscription::PAYMENT_METHOD_CREEM, Subscription::defaultPaymentMethod());
        });
    }

    private function checkout(array $params = [])
    {
        return $this->json('POST', route('api.v1.subscriptions.store'), array_merge([
            'planId'  => $this->plan->id,
            'priceId' => $this->monthly->id,
        ], $params));
    }

    private function fakeSession(): void
    {
        Http::fake(['*/checkout/session' => Http::response([
            'code' => 200,
            'msg'  => 'success',
            'data' => [
                'sessionId' => 'sess_new',
                'url'       => 'https://uat-checkout.clinkbill.com/sess_new',
            ],
        ])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentSessionBody(): array
    {
        $requests = Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/api/checkout/session'));

        $this->assertCount(1, $requests);

        return $requests->first()[0]->data();
    }

    private function pastSubscription(User $user): void
    {
        $user->subscriptions()->create([
            'plan_id'        => $this->plan->id,
            'price_id'       => $this->monthly->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
            'status'         => Subscription::STATUS_CANCELED,
            'start_date'     => now()->subMonths(6),
            'next_date'      => now()->subMonths(5),
        ]);
    }

    private function withDefaultProvider(string $value, callable $callback): void
    {
        $previous = getenv('PAYMENT_DEFAULT_PROVIDER');
        putenv("PAYMENT_DEFAULT_PROVIDER={$value}");
        $_ENV['PAYMENT_DEFAULT_PROVIDER'] = $_SERVER['PAYMENT_DEFAULT_PROVIDER'] = $value;

        try {
            $callback();
        } finally {
            putenv($previous === false ? 'PAYMENT_DEFAULT_PROVIDER' : 'PAYMENT_DEFAULT_PROVIDER=' . $previous);
            $_ENV['PAYMENT_DEFAULT_PROVIDER'] = $_SERVER['PAYMENT_DEFAULT_PROVIDER'] = $previous === false ? null : $previous;
        }
    }
}
