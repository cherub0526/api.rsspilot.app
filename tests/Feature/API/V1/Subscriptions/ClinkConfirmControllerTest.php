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
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * `POST /v1/subscriptions/clink/confirm`：付完款回站時當場開通，不必等 webhook。
 * 前端帶來的 session_id 只當索引，其餘一律向 Clink 查證。
 *
 * @internal
 * @coversNothing
 */
class ClinkConfirmControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TRIAL_END_MS = 1_893_456_000_000;

    // ================================================================

    /** @var null|array<string, mixed> */
    private ?array $apiState = null;

    public function testRequiresAuthentication(): void
    {
        $this->json('POST', route('api.v1.subscriptions.clink.confirm.store'), ['session_id' => 'sess_x'])
            ->assertStatus(401);
    }

    public function testRequiresASessionId(): void
    {
        $this->fakeLogin();

        $this->json('POST', route('api.v1.subscriptions.clink.confirm.store'), [])
            ->assertStatus(422)
            ->assertJsonPath('messages.session_id.0', __('validators.controllers.subscription.session_id_required'));
    }

    /** 不是 sess_ 開頭的值直接拒絕，不拿去打 Clink。 */
    public function testRejectsSomethingThatIsNotASessionId(): void
    {
        Http::fake();
        $this->fakeLogin();

        foreach (['sub_1', '../../admin', '01m37jd2bwqy47cr9h45jmsqky'] as $id) {
            $this->confirm($id)->assertStatus(422);
        }

        Http::assertNothingSent();
    }

    public function testConfirmsACompletedSessionAndSyncsTheTrial(): void
    {
        $user = $this->fakeLogin();
        $subscription = $this->pendingSubscription($user);
        $this->fakeClink($this->sessionFor($subscription));

        $this->confirm('sess_1')
            ->assertStatus(200)
            ->assertJsonPath('subscription_id', $subscription->id)
            ->assertJsonPath('status', Subscription::STATUS_TRIAL);

        $this->assertSame(self::TRIAL_END_MS, $subscription->fresh()->next_date->getTimestampMs());
        $this->assertDatabaseHas('clinks', ['foreign_id' => $subscription->id, 'clink_id' => 'sub_1']);
    }

    /** 查無、未完成、沒有訂閱、不屬於本人、API 失敗，一律同一則 422。 */
    public function testRejectsEverySessionItCannotConfirmWithOneMessage(): void
    {
        $user = $this->fakeLogin();
        $subscription = $this->pendingSubscription($user);
        $someoneElses = $this->pendingSubscription(User::factory()->create());

        $cases = [
            'not completed'   => $this->sessionFor($subscription, ['status' => 'open']),
            'no subscription' => $this->sessionFor($subscription, ['subscriptionId' => null]),
            'someone elses'   => $this->sessionFor($someoneElses),
            'no reference'    => $this->sessionFor($subscription, ['metadata' => [], 'merchantReferenceId' => null]),
        ];

        foreach ($cases as $case => $session) {
            $this->fakeClink($session);

            $this->confirm('sess_1')
                ->assertStatus(422)
                ->assertJsonPath(
                    'messages.session_id.0',
                    __('validators.controllers.subscription.checkout_not_confirmable')
                );

            $this->assertSame(Subscription::STATUS_PAYING, $subscription->fresh()->status, $case);
            $this->assertSame(Subscription::STATUS_PAYING, $someoneElses->fresh()->status, $case);
        }

        $this->apiState = null;
        Http::fake(['*' => Http::response(['code' => 404, 'msg' => 'not found'], 404)]);

        $this->confirm('sess_1')->assertStatus(422);
    }

    private function confirm(string $sessionId)
    {
        return $this->json('POST', route('api.v1.subscriptions.clink.confirm.store'), ['session_id' => $sessionId]);
    }

    private function pendingSubscription(User $user): Subscription
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create());
        $price = Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $plan->id, 'price' => '12.99']));

        $subscription = Subscription::factory()->create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CLINK,
            'status'         => Subscription::STATUS_PAYING,
            'next_date'      => null,
        ]);

        $subscription->clink()->create([
            'foreign_type' => Subscription::class,
            'clink_id'     => 'sess_' . $subscription->id,
            'variant'      => Clink::VARIANT_TRIAL,
        ]);

        return $subscription;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionFor(Subscription $subscription, array $overrides = []): array
    {
        return array_merge([
            'sessionId'           => 'sess_1',
            'status'              => 'completed',
            'paymentStatus'       => 'paid',
            'subscriptionId'      => 'sub_1',
            'merchantReferenceId' => $subscription->id,
            'metadata'            => ['subscriptionId' => $subscription->id],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function fakeClink(array $session): void
    {
        $first = $this->apiState === null;
        $this->apiState = $session;

        if (!$first) {
            return;
        }

        Http::fake([
            '*/checkout/session/*' => fn () => Http::response(['code' => 200, 'msg' => 'success', 'data' => $this->apiState]),
            '*/subscription/sub_1' => Http::response(['code' => 200, 'msg' => 'success', 'data' => [
                'subscriptionId' => 'sub_1',
                'status'         => 'free_trial',
                'createTime'     => 1_761_900_000_000,
                'trialEnd'       => self::TRIAL_END_MS,
            ]]),
        ]);
    }
}
