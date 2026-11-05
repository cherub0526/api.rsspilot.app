<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Clink;
use App\Models\Price;
use App\Models\Subscription;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Event;
use Hypervel\Log\Events\MessageLogged;
use PHPUnit\Framework\Attributes\DataProvider;
use Hypervel\HttpClient\Request as HttpRequest;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\Http\TestResponse;

/**
 * hidden-plan-link 驗收（T03）：`POST /v1/subscriptions` 檢查方案狀態與連結碼，`GET /v1/subscriptions` 帶 hidden。
 *
 * 情境：S03b、S03c、S06、S06b、S06c、S06d、S07、S08、S10、S11、S16（結帳的部分）、S18、S18b、S20（結帳）。
 * 檢查順序（SR-2）：plan 存在 → active → hidden 時 accessCode（32 字元非空字串，hash_equals）→ price → already_subscribed。
 * 所有方案層的失敗都回同一個 `{"planId": ["<plan_not_found>"]}`，不建訂閱、不呼叫金流。
 * Clink API 一律由 Http::fake 攔下。
 *
 * @internal
 * @coversNothing
 */
class SubscriptionsHiddenPlanTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER_CODE = 'K7fQ2mZp9xLr4TbW8nVc3HsY6dJa1GeU';

    private const EARLY_CODE = 'E9xR5tKw2pLm7QzN4bVh8CsD3fYa6JgT';

    /** 與 phpunit.xml.dist 的 CLINK_WEBHOOK_SECRET 一致（同 ClinkControllerTest）。 */
    private const WEBHOOK_SECRET = 'clink_phpunit_signing';

    /** @var array<string, Plan> */
    private array $plans = [];

    /** @var array<string, Price> 每個方案的月繳 price */
    private array $prices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan('Free', Plan::VISIBILITY_PUBLIC, null, 0, ['channel_limit' => 3]);
        $this->plan('Pro', Plan::VISIBILITY_PUBLIC, null, 12.99);
        $this->plan('Advance', Plan::VISIBILITY_PUBLIC, null, 24.99);
        $this->plan('Partner', Plan::VISIBILITY_HIDDEN, self::PARTNER_CODE, 9.99, ['channel_limit' => 42]);
        $this->plan('Early', Plan::VISIBILITY_HIDDEN, self::EARLY_CODE, 4.99);
    }

    private function plan(string $title, string $visibility, ?string $code, float $monthly, array $attributes = [], string $status = Plan::STATUS_ACTIVE): Plan
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['title' => $title, 'status' => $status] + $attributes));
        Plan::query()->whereKey($plan->id)->update(['visibility' => $visibility, 'access_code' => $code]);
        $price = Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $plan->id, 'unit' => Price::UNIT_MONTHLY, 'price' => $monthly]));

        $key = strtolower($title);
        Clink::create(['foreign_type' => Plan::class, 'foreign_id' => $plan->id, 'clink_id' => "prd_{$key}", 'variant' => Clink::VARIANT_STANDARD]);
        foreach ([Clink::VARIANT_STANDARD, Clink::VARIANT_TRIAL] as $variant) {
            Clink::create(['foreign_type' => Price::class, 'foreign_id' => $price->id, 'clink_id' => "price_{$key}_{$variant}", 'variant' => $variant]);
        }

        $this->prices[$title] = $price;

        return $this->plans[$title] = $plan->refresh();
    }

    /** fakeLogin() 的回傳型別是 Model 的聯集，這裡要 User。 */
    private function login(): User
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(User::class, $user);
        $this->actingAs($user);

        return $user;
    }

    private function fakeCheckout(): void
    {
        Http::fake(['*/checkout/session' => Http::response([
            'code' => 200,
            'msg'  => 'success',
            'data' => ['sessionId' => 'sess_new', 'url' => 'https://uat-checkout.clinkbill.com/sess_new'],
        ])]);
    }

    /** @param array<string, mixed> $extra */
    private function subscribe(string $title, array $extra = []): TestResponse
    {
        return $this->json('POST', route('api.v1.subscriptions.store'), array_merge([
            'planId'        => $this->plans[$title]->id,
            'priceId'       => $this->prices[$title]->id,
            'paymentMethod' => Subscription::PAYMENT_METHOD_CLINK,
        ], $extra));
    }

    private function assertPlanNotFound(TestResponse $response): void
    {
        $response->assertStatus(422);
        $this->assertSame([__('validators.controllers.subscription.plan_not_found')], $response->json('messages.planId'));
        $this->assertNull($response->json('messages.priceId'));
        $this->assertNull($response->json('messages.subscription'));
    }

    private function assertNoCheckout(): void
    {
        $this->assertSame(0, Subscription::query()->count(), 'no subscription row');
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function sentSession(): array
    {
        $requests = Http::recorded(fn (HttpRequest $request) => str_ends_with($request->url(), '/api/checkout/session'));
        $this->assertCount(1, $requests);

        return $requests->first()[0]->data();
    }

    private function subscriptionFor(User $user, string $title, string $status = Subscription::STATUS_ACTIVE): Subscription
    {
        $subscription = Subscription::factory()->create([
            'user_id'        => $user->id,
            'plan_id'        => $this->plans[$title]->id,
            'price_id'       => $this->prices[$title]->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CLINK,
            'status'         => $status,
            'start_date'     => now()->subMonth(),
            'next_date'      => now()->addDays(5),
        ]);
        $this->assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    // ── S03b、S03c：帶正確連結碼結帳 ──────────────────

    public function testS03bACorrectCodeChecksOutLikeAPublicPlanWithTheTrial(): void
    {
        $this->fakeCheckout();
        $user = $this->login();

        $this->subscribe('Partner', ['accessCode' => self::PARTNER_CODE])
            ->assertStatus(200)
            ->assertJsonPath('clink.checkout_url', 'https://uat-checkout.clinkbill.com/sess_new');

        /** @var Subscription $subscription */
        $subscription = Subscription::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($this->plans['Partner']->id, $subscription->plan_id);
        $this->assertSame('price_partner_trial', $this->sentSession()['priceId'], 'first subscription gets the 14-day trial');
        $this->assertSame('prd_partner', $this->sentSession()['productId']);
    }

    public function testS03cSomeoneWhoSubscribedBeforeGetsNoTrial(): void
    {
        $this->fakeCheckout();
        $user = $this->login();
        $this->subscriptionFor($user, 'Pro', Subscription::STATUS_CANCELED)->fill(['next_date' => now()->subMonths(2)])->save();

        $this->subscribe('Partner', ['accessCode' => self::PARTNER_CODE])->assertStatus(200);

        $this->assertSame('price_partner_standard', $this->sentSession()['priceId']);
    }

    #[DataProvider('wrongCodes')]
    public function testS06AWrongCodeCannotSubscribeToAHiddenPlan(array $extra): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->login();

        $this->assertPlanNotFound($this->subscribe('Partner', $extra));
        $this->assertNoCheckout();
    }

    // ── S06、S06b：連結碼不對 ─────────────────────────

    /** @return array<string, array{array<string, mixed>}> */
    public static function wrongCodes(): array
    {
        return [
            'no accessCode'         => [[]],
            'empty string'          => [['accessCode' => '']],
            'null'                  => [['accessCode' => null]],
            'wrong code'            => [['accessCode' => 'WrongCode000000000000000000000ZZ']],
            "Early's valid code"    => [['accessCode' => self::EARLY_CODE]],
            'right code, lowercase' => [['accessCode' => strtolower(self::PARTNER_CODE)]],
            'right code + suffix'   => [['accessCode' => self::PARTNER_CODE . 'X']],
            'right code prefix'     => [['accessCode' => substr(self::PARTNER_CODE, 0, 31)]],
        ];
    }

    /** S06b：access_code 是 null 的隱藏方案，不能用「沒帶」或空字串去對上 null。 */
    #[DataProvider('emptyCodes')]
    public function testS06bAHiddenPlanWithoutACodeCannotBeSubscribedWithAnEmptyValue(array $extra): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->plan('Draft', Plan::VISIBILITY_HIDDEN, null, 9.99);
        $this->login();

        $this->assertPlanNotFound($this->subscribe('Draft', $extra));
        $this->assertNoCheckout();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function emptyCodes(): array
    {
        return ['no accessCode' => [[]], 'empty string' => [['accessCode' => '']], 'null' => [['accessCode' => null]]];
    }

    // ── S06c：方案的檢查排在 price 之前 ────────────────

    public function testS06cThePlanIsCheckedBeforeThePrice(): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->login();

        $this->assertPlanNotFound($this->subscribe('Partner', ['priceId' => '01JCXYZ0000000000000000000']));
        // 換成別的方案的 price 也一樣
        $this->assertPlanNotFound($this->subscribe('Partner', ['priceId' => $this->prices['Pro']->id]));
        $this->assertNoCheckout();
    }

    #[DataProvider('inactivePlans')]
    public function testS07AnInactivePlanCannotBeSubscribedAndLooksLikeS06(string $title, array $extra): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->login();
        $s06 = $this->subscribe('Partner', ['accessCode' => 'WrongCode000000000000000000000ZZ']);
        Plan::query()->whereKey($this->plans[$title]->id)->update(['status' => Plan::STATUS_INACTIVE]);

        $response = $this->subscribe($title, $extra);

        $this->assertPlanNotFound($response);
        $this->assertSame($s06->getStatusCode(), $response->getStatusCode());
        $this->assertSame((string) $s06->getContent(), (string) $response->getContent(), 'same body as S06');
        $this->assertNoCheckout();
    }

    // ── S07：inactive 方案不能訂，回應和 S06 完全相同 ──

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function inactivePlans(): array
    {
        return [
            'public plan, no accessCode'  => ['Pro', []],
            'Partner, correct accessCode' => ['Partner', ['accessCode' => self::PARTNER_CODE]],
        ];
    }

    // ── S06d：失敗時不呼叫金流（S06、S06b、S07 各自已斷言 assertNoCheckout） ──

    public function testS06dNoFailureReachesThePaymentProviderEvenWithEveryProviderMethod(): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->login();

        foreach (Subscription::checkoutPaymentMethods() as $method) {
            $this->assertPlanNotFound($this->subscribe('Partner', ['paymentMethod' => $method]));
        }
        $this->assertNoCheckout();
    }

    // ── S11：先檢查方案與連結碼，再檢查是否已訂閱 ─────

    public function testS11ThePlanAndCodeAreCheckedBeforeAlreadySubscribed(): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $user = $this->login();
        $this->subscriptionFor($user, 'Pro');

        $this->assertPlanNotFound($this->subscribe('Partner'));

        $response = $this->subscribe('Partner', ['accessCode' => self::PARTNER_CODE])->assertStatus(422);
        $this->assertSame([__('validators.controllers.subscription.already_subscribed')], $response->json('messages.subscription'));
        $this->assertSame(1, Subscription::query()->count());
        Http::assertNothingSent();
    }

    // ── SR-2 的其他規則 ───────────────────────────────

    /** 公開方案帶 accessCode 時忽略它，照常結帳。 */
    public function testAPublicPlanIgnoresAnAccessCode(): void
    {
        $this->fakeCheckout();
        $this->login();

        $this->subscribe('Pro', ['accessCode' => self::PARTNER_CODE])->assertStatus(200);
        $this->assertSame('prd_pro', $this->sentSession()['productId']);
    }

    /** 有連結碼也不能拿去訂別的隱藏方案（碼綁定方案）。 */
    public function testPartnersCodeDoesNotOpenEarly(): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->login();

        $this->assertPlanNotFound($this->subscribe('Early', ['accessCode' => self::PARTNER_CODE]));
        $this->assertNoCheckout();
    }

    /** accessCode 規則 `nullable|string|max:64`：型別或長度不對是 422，不是 500，也不結帳。 */
    #[DataProvider('invalidAccessCodeTypes')]
    public function testAnInvalidAccessCodeIsRejectedWithoutAServerError(mixed $accessCode): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->login();

        $this->subscribe('Pro', ['accessCode' => $accessCode])->assertStatus(422);
        $this->assertNoCheckout();
    }

    /** @return array<string, array{mixed}> */
    public static function invalidAccessCodeTypes(): array
    {
        return [
            'array'          => [[self::PARTNER_CODE]],
            'longer than 64' => [str_repeat('A', 65)],
        ];
    }

    // ── qa r1 邊界 ────────────────────────────────────

    /** T03 卡〈紀錄〉：request 裡夾帶 Plan 的欄位，結帳之後方案那一列完全不變。 */
    public function testCheckoutNeverWritesRequestInputIntoThePlan(): void
    {
        $this->fakeCheckout();
        $this->login();
        $before = DB::table('plans')->where('id', $this->plans['Partner']->id)->first();

        $this->subscribe('Partner', [
            'accessCode'  => self::PARTNER_CODE,
            'access_code' => 'Hijacked00000000000000000000000Z',
            'visibility'  => Plan::VISIBILITY_PUBLIC,
            'status'      => Plan::STATUS_INACTIVE,
            'title'       => 'Hijacked',
        ])->assertStatus(200);

        $this->assertEquals($before, DB::table('plans')->where('id', $this->plans['Partner']->id)->first());
    }

    /** 隱藏方案的碼長度不是 32（例如手動寫入的舊碼）：就算請求帶的碼完全相同，也訂不到。 */
    public function testAHiddenPlanWhoseStoredCodeIsNot32CharactersCannotBeSubscribed(): void
    {
        $this->fakeCheckout();
        Plan::query()->whereKey($this->plans['Partner']->id)->update(['access_code' => 'short']);
        $this->login();

        $this->assertPlanNotFound($this->subscribe('Partner', ['accessCode' => 'short']));
        $this->assertNoCheckout();
    }

    /** visibility 是 public、hidden 以外的值：一律不開放結帳。 */
    public function testAnUnknownVisibilityIsNotOpenForCheckout(): void
    {
        $this->fakeCheckout();
        Plan::query()->whereKey($this->plans['Partner']->id)->update(['visibility' => 'internal']);
        $this->login();

        $this->assertPlanNotFound($this->subscribe('Partner', ['accessCode' => self::PARTNER_CODE]));
        $this->assertNoCheckout();
    }

    /** max:64 的邊界：剛好 64 字元可以通過驗證，公開方案照常結帳。 */
    public function testASixtyFourCharacterAccessCodeIsStillAccepted(): void
    {
        $this->fakeCheckout();
        $this->login();

        $this->subscribe('Pro', ['accessCode' => str_repeat('A', 64)])->assertStatus(200);
    }

    /** accessCode 是數字（JSON number）：422，不是 500。 */
    public function testANumericAccessCodeIsRejectedWithoutAServerError(): void
    {
        $this->fakeCheckout();
        $this->login();

        $this->subscribe('Pro', ['accessCode' => 12345])->assertStatus(422);
        $this->assertNoCheckout();
    }

    // ── S16（結帳的部分） ─────────────────────────────

    public function testS16CheckoutNeverLogsTheCode(): void
    {
        $this->fakeCheckout();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message . ' ' . json_encode($event->context);
        });
        $this->login();

        $this->subscribe('Partner', ['accessCode' => 'WrongCode000000000000000000000ZZ']);
        $this->subscribe('Partner', ['accessCode' => self::PARTNER_CODE])->assertStatus(200);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::PARTNER_CODE, $line);
            $this->assertStringNotContainsString('WrongCode000000000000000000000ZZ', $line);
        }
        // 送到金流的參數裡也沒有連結碼
        $this->assertStringNotContainsString(self::PARTNER_CODE, (string) json_encode($this->sentSession()));
    }

    // ── S10：訂閱回應帶 hidden ────────────────────────

    public function testS10TheSubscriptionResponseStaysFlatAndSaysHidden(): void
    {
        $user = $this->login();
        $this->subscriptionFor($user, 'Partner');

        $response = $this->json('GET', route('api.v1.subscriptions.index'))->assertStatus(200);

        $this->assertSame($this->plans['Partner']->id, $response->json('id'));
        $this->assertSame('Partner', $response->json('title'));
        $this->assertSame(42, $response->json('channel_limit'));
        $this->assertTrue($response->json('hidden'));
        $this->assertNull($response->json('plan'), 'no nested plan object (plan r2)');
        $this->assertStringNotContainsString(self::PARTNER_CODE, (string) $response->getContent());
        $this->assertStringNotContainsString('access_code', (string) $response->getContent());
    }

    public function testAPublicSubscriptionSaysHiddenFalse(): void
    {
        $user = $this->login();
        $this->subscriptionFor($user, 'Pro');

        $this->assertFalse($this->json('GET', route('api.v1.subscriptions.index'))->assertStatus(200)->json('hidden'));
    }

    // ── S08：方案關閉後，既有訂閱者不受影響 ───────────

    public function testS08AnExistingSubscriberKeepsThePlanAfterItIsDeactivated(): void
    {
        $user = $this->login();
        $this->subscriptionFor($user, 'Partner');
        Plan::query()->whereKey($this->plans['Partner']->id)->update(['status' => Plan::STATUS_INACTIVE]);

        $response = $this->json('GET', route('api.v1.subscriptions.index'))->assertStatus(200);

        $this->assertSame('Partner', $response->json('title'));
        $this->assertSame(42, $response->json('channel_limit'), 'entitlements still come from Partner');
    }

    public function testS08ARenewalWebhookStillExtendsASubscriptionToAnInactivePlan(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(User::class, $user);
        $subscription = $this->subscriptionFor($user, 'Partner');
        $subscription->clink()->create(['foreign_type' => Subscription::class, 'clink_id' => 'sub_partner', 'variant' => Clink::VARIANT_STANDARD]);
        Plan::query()->whereKey($this->plans['Partner']->id)->update(['status' => Plan::STATUS_INACTIVE]);
        $before = $subscription->refresh()->next_date;

        $periodEndMs = (int) (now()->addDays(35)->getTimestamp() * 1000);
        $state = [
            'subscriptionId'       => 'sub_partner',
            'customerId'           => 'cus_1',
            'productId'            => 'prd_partner',
            'priceId'              => 'price_partner_standard',
            'status'               => 'active',
            'createTime'           => (int) (now()->subMonth()->getTimestamp() * 1000),
            'currentPeriodStart'   => (int) (now()->getTimestamp() * 1000),
            'currentPeriodEnd'     => $periodEndMs - 1000,
            'recurringInvoiceItem' => ['periodStart' => (int) (now()->getTimestamp() * 1000), 'periodEnd' => $periodEndMs],
            'cancelAtPeriodEnd'    => false,
            'canceledAt'           => null,
            'merchantReference'    => $subscription->id,
            'metadata'             => ['subscriptionId' => $subscription->id],
        ];
        Http::fake(['*/subscription/*' => Http::response(['code' => 200, 'msg' => 'success', 'data' => $state])]);

        $this->clinkWebhook('subscription.updated.renewed', $state)->assertStatus(200);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->next_date->greaterThan($before), 'renewal extends next_date');
    }

    /**
     * 「排序在 Free 前面」：先刪掉 Free，建好 lookalike 之後再建回 Free，讓它在 id、建立順序、sort 上都排在 Free 前面。
     */
    #[DataProvider('freeLookalikes')]
    public function testS18AMemberWithoutASubscriptionDefaultsToThePublicActiveFree(string $title, string $visibility): void
    {
        $this->recreateFreeAfter($title, $visibility);
        $this->login();

        $response = $this->json('GET', route('api.v1.subscriptions.index'))->assertStatus(200);

        $this->assertSame('Free', $response->json('title'));
        $this->assertSame(3, $response->json('channel_limit'), "entitlements are Free's");
        $this->assertFalse($response->json('hidden'));
    }

    // ── S18、S18b：預設方案一定是公開且 active 的 Free ─

    /** @return array<string, array{string, string}> */
    public static function freeLookalikes(): array
    {
        return [
            'active hidden FreePartner' => ['FreePartner', Plan::VISIBILITY_HIDDEN],
            'inactive public OldFree'   => ['OldFree', Plan::VISIBILITY_PUBLIC],
        ];
    }

    public function testS18bAMemberWhoReallySubscribedToTheFreeHiddenPlanKeepsIt(): void
    {
        $this->recreateFreeAfter('FreePartner', Plan::VISIBILITY_HIDDEN);
        $user = $this->login();
        $this->subscriptionFor($user, 'FreePartner');

        $this->assertSame('FreePartner', $this->json('GET', route('api.v1.subscriptions.index'))->assertStatus(200)->json('title'));
    }

    private function recreateFreeAfter(string $title, string $visibility): void
    {
        $free = $this->plans['Free'];
        Clink::query()->whereIn('foreign_id', [$free->id, $this->prices['Free']->id])->delete();
        Price::query()->where('plan_id', $free->id)->delete();
        Plan::query()->whereKey($free->id)->delete();

        $status = $title === 'OldFree' ? Plan::STATUS_INACTIVE : Plan::STATUS_ACTIVE;
        $this->plan($title, $visibility, $visibility === Plan::VISIBILITY_HIDDEN ? str_repeat('F', 32) : null, 0, ['channel_limit' => 99, 'sort' => 0], $status);
        $this->plan('Free', Plan::VISIBILITY_PUBLIC, null, 0, ['channel_limit' => 3, 'sort' => 1]);
    }

    // ── S20（結帳） ───────────────────────────────────

    public function testS20CheckoutIsThrottledAtTenRequestsAMinutePerUser(): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->login();

        for ($i = 0; $i < 10; ++$i) {
            $this->assertPlanNotFound($this->subscribe('Partner'));
        }
        $this->subscribe('Partner')->assertStatus(429);
    }

    /** 計數鍵是 user：另一個會員不受前一個人的限流影響。 */
    public function testTheCheckoutThrottleIsPerUser(): void
    {
        $this->fakeCheckout(); // 讓「漏擋」在 RED 時是 200，而不是金流 mock 沒回應的 500
        $this->login();
        for ($i = 0; $i < 11; ++$i) {
            $this->subscribe('Partner');
        }

        $this->login();
        $this->assertPlanNotFound($this->subscribe('Partner'));
    }

    // ── helpers ───────────────────────────────────────

    /** 同 ClinkControllerTest：rawBody 要和測試客戶端送出的位元組一致。 */
    private function clinkWebhook(string $type, array $object): TestResponse
    {
        $payload = ['id' => 'event_' . md5($type . json_encode($object)), 'object' => 'event', 'type' => $type, 'created' => (int) (now()->getTimestamp() * 1000), 'data' => ['object' => $object]];
        $timestamp = (string) (int) (microtime(true) * 1000);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->createTestResponse($this->getTestingClient()->json('POST', route('api.v1.webhook.clink.store'), $payload, [
            'X-Clink-Timestamp' => $timestamp,
            'X-Clink-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, self::WEBHOOK_SECRET),
            'X-Clink-SignType'  => 'SHA256',
        ]));
    }
}
