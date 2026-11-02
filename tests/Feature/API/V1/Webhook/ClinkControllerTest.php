<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Webhook;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Clink;
use App\Models\Price;
use App\Models\Subscription;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Http\Controllers\API\V1\Webhook\ClinkController;

/**
 * `POST /v1/webhook/clink`：驗簽、分派，以及「事件只當通知、狀態以 API 為準」。
 * Clink API 一律由 Http::fake 攔下。
 *
 * @internal
 * @coversNothing
 */
class ClinkControllerTest extends TestCase
{
    use RefreshDatabase;

    /** 與 phpunit.xml.dist 的 CLINK_WEBHOOK_SECRET 一致。 */
    private const SECRET = 'clink_phpunit_signing';

    private const TRIAL_END_MS = 1_893_456_000_000;

    private const PERIOD_END_MS = 1_896_134_400_000;

    private const CREATED_MS = 1_761_900_000_000;

    private const CANCELED_MS = 1_762_000_000_000;

    private Subscription $subscription;

    /** @var null|array<string, mixed> */
    private ?array $apiState = null;

    public function setUp(): void
    {
        parent::setUp();

        $plan = Plan::withoutEvents(fn () => Plan::factory()->create());
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => '12.99',
        ]));

        $this->subscription = Subscription::factory()->create([
            'user_id'        => User::factory()->create()->id,
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CLINK,
            'status'         => Subscription::STATUS_PAYING,
            'next_date'      => null,
        ]);

        $this->subscription->clink()->create([
            'foreign_type' => Subscription::class,
            'clink_id'     => 'sess_1',
            'variant'      => Clink::VARIANT_TRIAL,
        ]);
    }

    // ---- 驗簽 ----

    /** 驗簽通過、事件不處理 → 200，而且不打 Clink API。 */
    public function testStoreAcknowledgesAVerifiedUnhandledEvent(): void
    {
        Http::fake();

        foreach (['order.succeeded', 'refund.created', 'dispute.created', 'session.complete', 'subscription.created'] as $type) {
            $this->send($this->event($type, []))->assertStatus(200);
        }

        Http::assertNothingSent();
    }

    public function testStoreRejectsEveryKindOfBadSignatureWithOneMessage(): void
    {
        Http::fake();
        $payload = $this->event('subscription.activated', $this->subscriptionObject('active'));
        $now = (string) $this->nowMs();
        $stale = (string) ($this->nowMs() - 6 * 60 * 1000);
        $future = (string) ($this->nowMs() + 6 * 60 * 1000);

        $cases = [
            'no headers'      => [],
            'no sign type'    => $this->headers($payload, $now, signType: null),
            'wrong sign type' => $this->headers($payload, $now, signType: 'MD5'),
            'no timestamp'    => array_diff_key($this->headers($payload, $now), ['X-Clink-Timestamp' => true]),
            'non-numeric ts'  => $this->headers($payload, 'abc'),
            'stale ts'        => $this->headers($payload, $stale),
            'future ts'       => $this->headers($payload, $future),
            'wrong secret'    => $this->headers($payload, $now, secret: 'nope'),
            'no signature'    => array_diff_key($this->headers($payload, $now), ['X-Clink-Signature' => true]),
        ];

        foreach ($cases as $case => $headers) {
            $this->send($payload, $headers)
                ->assertStatus(422)
                ->assertJsonPath('messages.signature.0', 'Invalid Clink webhook signature.');
            $this->assertSame(Subscription::STATUS_PAYING, $this->subscription->fresh()->status, $case);
        }

        $tampered = $this->event('subscription.activated', $this->subscriptionObject('cancelled'));
        $this->send($tampered, $this->headers($payload, $now))->assertStatus(422);

        Http::assertNothingSent();
    }

    public function testStoreRejectsEverythingWhenTheSecretIsNotConfigured(): void
    {
        Http::fake();
        $payload = $this->event('order.succeeded', []);
        $previous = getenv('CLINK_WEBHOOK_SECRET');
        putenv('CLINK_WEBHOOK_SECRET=');
        $_ENV['CLINK_WEBHOOK_SECRET'] = $_SERVER['CLINK_WEBHOOK_SECRET'] = '';

        try {
            $this->send($payload, $this->headers($payload, (string) $this->nowMs(), secret: ''))
                ->assertStatus(422)
                ->assertJsonPath('messages.signature.0', 'Invalid Clink webhook signature.');
        } finally {
            putenv('CLINK_WEBHOOK_SECRET=' . $previous);
            $_ENV['CLINK_WEBHOOK_SECRET'] = $_SERVER['CLINK_WEBHOOK_SECRET'] = $previous;
        }
    }

    public function testStoreRejectsAMalformedPayload(): void
    {
        foreach ([['type' => 'subscription.activated'], ['data' => ['object' => []]]] as $payload) {
            $this->send($payload)
                ->assertStatus(422)
                ->assertJsonPath('messages.payload.0', __('validators.controllers.webhook.clink.malformed_payload'));
        }
    }

    // ---- 生命週期 ----

    public function testTrialingStartsTheTrialUntilTrialEnd(): void
    {
        $this->fakeApi($this->subscriptionObject('free_trial'));

        $this->send($this->event('subscription.trialing', $this->subscriptionObject('free_trial')))->assertStatus(200);

        $fresh = $this->subscription->fresh();
        $this->assertSame(Subscription::STATUS_TRIAL, $fresh->status);
        $this->assertSame(self::TRIAL_END_MS, $fresh->next_date->getTimestampMs());
        $this->assertSame(self::CREATED_MS, $fresh->start_date->getTimestampMs());
        $this->assertNull($fresh->cancellation_date);
    }

    public function testActivatedUsesTheRecurringItemPeriodEnd(): void
    {
        $this->fakeApi($this->subscriptionObject('active'));

        $this->send($this->event('subscription.activated', $this->subscriptionObject('active')))->assertStatus(200);

        $fresh = $this->subscription->fresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(self::PERIOD_END_MS, $fresh->next_date->getTimestampMs());
    }

    /** past_due 時 Clink 還在重試，比照 Creem 不停權。 */
    public function testPastDueKeepsAccess(): void
    {
        $this->fakeApi($this->subscriptionObject('past_due'));

        $this->send($this->event('subscription.past_due', $this->subscriptionObject('past_due')))->assertStatus(200);

        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);
    }

    public function testIncompleteStaysPaying(): void
    {
        $this->fakeApi($this->subscriptionObject('incomplete'));

        $this->send($this->event('subscription.activated', $this->subscriptionObject('incomplete')))->assertStatus(200);

        $this->assertSame(Subscription::STATUS_PAYING, $this->subscription->fresh()->status);
    }

    /** 已結束與認不得的狀態一律 fail closed 成 canceled。 */
    public function testEndedAndUnknownStatusesCancel(): void
    {
        foreach (['cancelled', 'incomplete_expired', 'paused_forever'] as $status) {
            $this->subscription->fill(['status' => Subscription::STATUS_ACTIVE])->save();
            $this->fakeApi($this->subscriptionObject($status, ['canceledAt' => self::CANCELED_MS]));

            $this->send($this->event('subscription.cancelled', $this->subscriptionObject($status)))->assertStatus(200);

            $this->assertSame(Subscription::STATUS_CANCELED, $this->subscription->fresh()->status, $status);
        }
    }

    /** 期末取消：權限保留到期末，記下取消時間；撤回時清掉。 */
    public function testCancelAtPeriodEndIsRecordedAndRevoked(): void
    {
        $scheduled = $this->subscriptionObject('active', ['cancelAtPeriodEnd' => true, 'canceledAt' => self::CANCELED_MS]);
        $this->fakeApi($scheduled);

        $this->send($this->event('subscription.updated.cancel_at_period_end_set', $scheduled))->assertStatus(200);

        $fresh = $this->subscription->fresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(self::CANCELED_MS, $fresh->cancellation_date->getTimestampMs());

        $revoked = $this->subscriptionObject('active', ['cancelAtPeriodEnd' => false, 'canceledAt' => null]);
        $this->fakeApi($revoked);

        $this->send($this->event('subscription.updated.cancel_at_period_end_revoked', $revoked))->assertStatus(200);

        $this->assertNull($this->subscription->fresh()->cancellation_date);
    }

    /**
     * 亂序：先到的是較新的 cancelled、後到的是較舊的 trialing。狀態以回查 API 為準，
     * 所以晚到的舊事件蓋不掉已結束的訂閱。
     */
    public function testOutOfOrderEventsConvergeOnTheApiState(): void
    {
        $this->fakeApi($this->subscriptionObject('cancelled', ['canceledAt' => self::CANCELED_MS]));

        $this->send($this->event('subscription.cancelled', $this->subscriptionObject('cancelled')))->assertStatus(200);
        $this->send($this->event('subscription.trialing', $this->subscriptionObject('free_trial')))->assertStatus(200);

        $this->assertSame(Subscription::STATUS_CANCELED, $this->subscription->fresh()->status);
    }

    /** 第一則事件把 sess_ 改寫成 sub_；同一則事件重送不會多出一列。 */
    public function testTheSessionRowIsRewrittenToTheSubscriptionOnce(): void
    {
        $this->fakeApi($this->subscriptionObject('active'));
        $payload = $this->event('subscription.activated', $this->subscriptionObject('active'));

        $this->send($payload)->assertStatus(200);
        $this->send($payload)->assertStatus(200);

        $rows = Clink::query()->where('foreign_type', Subscription::class)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('sub_1', $rows->first()->clink_id);
        $this->assertSame('active', $rows->first()->clink_detail['status']);
        $this->assertSame(Clink::VARIANT_TRIAL, $rows->first()->variant);
    }

    /** metadata 缺漏時，以 clinks 表的 sessionId / subscriptionId 反查（invoice 物件沒有 metadata）。 */
    public function testInvoicePaidResolvesThroughTheMappingTable(): void
    {
        $this->fakeApi($this->subscriptionObject('active'));

        $invoice = ['invoiceId' => 'inv_1', 'subscriptionId' => 'sub_1', 'sessionId' => 'sess_1', 'status' => 'paid'];
        $this->send($this->event('invoice.paid', $invoice))->assertStatus(200);

        $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status);

        // 改寫成 sub_ 之後，只帶 subscriptionId 的事件也對得到
        $this->send($this->event('subscription.updated.renewed', ['subscriptionId' => 'sub_1']))->assertStatus(200);
    }

    /** 對不到訂閱就回 422，讓 Clink 重送並留下可追的紀錄。 */
    public function testUnknownSubscriptionIsRejected(): void
    {
        $this->fakeApi($this->subscriptionObject('active'));

        $this->send($this->event('subscription.activated', [
            'subscriptionId' => 'sub_someone_else',
            'metadata'       => ['subscriptionId' => '01JCXYZ000000000000000000'],
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['messages' => ['subscription']]);

        Http::assertNothingSent();
    }

    /** 回查 API 失敗時回非 2xx，讓 Clink 依退避重送。 */
    public function testApiFailureAsksClinkToRetry(): void
    {
        Http::fake(['*' => Http::response(['code' => 500, 'msg' => 'down'], 500)]);

        $response = $this->send($this->event('subscription.activated', $this->subscriptionObject('active')));

        $this->assertGreaterThanOrEqual(500, $response->getStatusCode());
        $this->assertSame(Subscription::STATUS_PAYING, $this->subscription->fresh()->status);
    }

    /** 每一個處理中的事件都會走到回查與同步。 */
    public function testEveryHandledEventSyncs(): void
    {
        $this->fakeApi($this->subscriptionObject('active'));

        foreach (ClinkController::HANDLED_EVENTS as $type) {
            $this->subscription->fill(['status' => Subscription::STATUS_PAYING])->save();

            $this->send($this->event($type, $this->subscriptionObject('active')))->assertStatus(200);

            $this->assertSame(Subscription::STATUS_ACTIVE, $this->subscription->fresh()->status, $type);
        }
    }

    // ================================================================

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private function event(string $type, array $object): array
    {
        return [
            'id'      => 'event_' . md5($type . json_encode($object)),
            'object'  => 'event',
            'type'    => $type,
            'created' => self::CREATED_MS,
            'data'    => ['object' => $object],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function subscriptionObject(string $status, array $overrides = []): array
    {
        return array_merge([
            'subscriptionId'       => 'sub_1',
            'sessionId'            => 'sess_1',
            'customerId'           => 'cus_1',
            'productId'            => 'prd_1',
            'priceId'              => 'price_1',
            'status'               => $status,
            'createTime'           => self::CREATED_MS,
            'trialStart'           => self::CREATED_MS,
            'trialEnd'             => self::TRIAL_END_MS,
            'currentPeriodStart'   => self::CREATED_MS,
            'currentPeriodEnd'     => self::PERIOD_END_MS - 1000,
            'recurringInvoiceItem' => ['periodStart' => self::TRIAL_END_MS, 'periodEnd' => self::PERIOD_END_MS],
            'cancelAtPeriodEnd'    => false,
            'canceledAt'           => null,
            'merchantReference'    => $this->subscription->id,
            'metadata'             => ['subscriptionId' => $this->subscription->id],
        ], $overrides);
    }

    /**
     * 讓 `GET /subscription/{id}` 回這個狀態。Http::fake 的 stub 會累加、先註冊的
     * 先命中，所以只註冊一次，之後改的是它讀的狀態。
     *
     * @param array<string, mixed> $subscription
     */
    private function fakeApi(array $subscription): void
    {
        $first = $this->apiState === null;
        $this->apiState = $subscription;

        if ($first) {
            Http::fake(['*/subscription/*' => fn () => Http::response(['code' => 200, 'msg' => 'success', 'data' => $this->apiState])]);
        }
    }

    /**
     * rawBody 必須與測試客戶端實際送出的位元組完全一致：`TestClient::json()` 用的是
     * `json_encode($data, JSON_UNESCAPED_UNICODE)`。
     *
     * @return array<string, string>
     */
    private function headers(array $payload, string $timestamp, ?string $signType = 'SHA256', string $secret = self::SECRET): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = [
            'X-Clink-Timestamp' => $timestamp,
            'X-Clink-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, $secret),
        ];

        if ($signType !== null) {
            $headers['X-Clink-SignType'] = $signType;
        }

        return $headers;
    }

    private function send(array $payload, ?array $headers = null)
    {
        return $this->createTestResponse(
            $this->getTestingClient()->json(
                'POST',
                route('api.v1.webhook.clink.store'),
                $payload,
                $headers ?? $this->headers($payload, (string) $this->nowMs())
            )
        );
    }
}
