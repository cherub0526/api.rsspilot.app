<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1\Subscriptions;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Clink;
use App\Models\Creem;
use App\Models\Price;
use App\Models\Subscription;
use Hypervel\Support\Facades\Http;
use Hypervel\HttpClient\Request as HttpRequest;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * `DELETE /v1/subscriptions/{id}` 的 Clink 分支，以及既有 Creem 訂閱仍回 Creem 取消。
 *
 * @internal
 * @coversNothing
 */
class ClinkCancelTest extends TestCase
{
    use RefreshDatabase;

    public function testCancelsAtPeriodEndThroughClink(): void
    {
        $user = $this->fakeLogin();
        $subscription = $this->activeSubscription($user, Subscription::PAYMENT_METHOD_CLINK);
        $this->link($subscription, 'sub_1');
        Http::fake(['*/subscription/sub_1/cancel' => $this->ok(['subscriptionId' => 'sub_1'])]);

        $this->destroy($subscription)->assertStatus(200);

        Http::assertSent(function (HttpRequest $request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/api/subscription/sub_1/cancel')
                && $request['cancelImmediately'] === false
                && is_string($request['reason']) && $request['reason'] !== '';
        });

        $fresh = $subscription->fresh();
        $this->assertNotNull($fresh->cancellation_date);
        $this->assertSame(Subscription::STATUS_ACTIVE, $fresh->status, '期末取消，權限保留到期末');
    }

    /** webhook 還沒進來時手上只有 session id：回頭查 session 拿訂閱 id 再取消，並改寫對應列。 */
    public function testResolvesTheSubscriptionFromTheSessionBeforeCancelling(): void
    {
        $user = $this->fakeLogin();
        $subscription = $this->activeSubscription($user, Subscription::PAYMENT_METHOD_CLINK);
        $this->link($subscription, 'sess_1');
        Http::fake([
            '*/checkout/session/sess_1'   => $this->ok(['sessionId' => 'sess_1', 'subscriptionId' => 'sub_1']),
            '*/subscription/sub_1/cancel' => $this->ok(['subscriptionId' => 'sub_1']),
        ]);

        $this->destroy($subscription)->assertStatus(200);

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/api/subscription/sub_1/cancel'));
        $this->assertDatabaseHas('clinks', ['foreign_id' => $subscription->id, 'clink_id' => 'sub_1']);
    }

    /** session 沒有訂閱（沒付完款）就沒有東西可取消：422，不能記成已取消。 */
    public function testRefusesWhenTheSessionHasNoSubscription(): void
    {
        $user = $this->fakeLogin();
        $subscription = $this->activeSubscription($user, Subscription::PAYMENT_METHOD_CLINK);
        $this->link($subscription, 'sess_1');
        Http::fake(['*/checkout/session/sess_1' => $this->ok(['sessionId' => 'sess_1', 'subscriptionId' => null])]);

        $this->destroy($subscription)
            ->assertStatus(422)
            ->assertJsonPath('messages.subscription.0', __('validators.controllers.subscription.cancel_unavailable'));

        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/cancel'));
        $this->assertNull($subscription->fresh()->cancellation_date);
    }

    public function testRefusesWhenThereIsNoClinkLink(): void
    {
        $user = $this->fakeLogin();
        $subscription = $this->activeSubscription($user, Subscription::PAYMENT_METHOD_CLINK);
        Http::fake();

        $this->destroy($subscription)->assertStatus(422);

        Http::assertNothingSent();
    }

    /** 既有 Creem 訂閱不搬移：取消仍然打 Creem，不碰 Clink。 */
    public function testExistingCreemSubscribersStillCancelThroughCreem(): void
    {
        $user = $this->fakeLogin();
        $subscription = $this->activeSubscription($user, Subscription::PAYMENT_METHOD_CREEM);
        Creem::create([
            'foreign_type' => Subscription::class,
            'foreign_id'   => $subscription->id,
            'creem_id'     => 'sub_creem_1',
        ]);
        Http::fake(['*/subscriptions/sub_creem_1/cancel' => Http::response(['id' => 'sub_creem_1'])]);

        $this->destroy($subscription)->assertStatus(200);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'creem.io')
            && str_ends_with($request->url(), '/subscriptions/sub_creem_1/cancel'));
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'clinkbill.com'));
    }

    // ================================================================

    private function destroy(Subscription $subscription)
    {
        return $this->json('DELETE', route('api.v1.subscriptions.destroy', ['subscriptionId' => $subscription->id]));
    }

    private function activeSubscription(User $user, string $paymentMethod): Subscription
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create());
        $price = Price::withoutEvents(fn () => Price::factory()->create(['plan_id' => $plan->id, 'price' => '12.99']));

        return Subscription::factory()->create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'price_id'       => $price->id,
            'payment_method' => $paymentMethod,
            'status'         => Subscription::STATUS_ACTIVE,
            'next_date'      => now()->addMonth(),
        ]);
    }

    private function link(Subscription $subscription, string $clinkId): void
    {
        $subscription->clink()->create([
            'foreign_type' => Subscription::class,
            'clink_id'     => $clinkId,
            'variant'      => Clink::VARIANT_STANDARD,
        ]);
    }

    private function ok(array $data)
    {
        return Http::response(['code' => 200, 'msg' => 'success', 'data' => $data]);
    }
}
