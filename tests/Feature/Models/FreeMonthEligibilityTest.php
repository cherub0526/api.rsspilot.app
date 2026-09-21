<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 首月免費的資格判定，以及「註冊不再送訂閱」這件事。
 *
 * @internal
 * @coversNothing
 */
class FreeMonthEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    private Plan $paidPlan;

    private Price $paidMonthlyPrice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freePlan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'         => 'Free',
            'channel_limit' => 1,
            'video_limit'   => 3,
        ]));
        Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->freePlan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 0,
        ]));

        $this->paidPlan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'         => 'Pro',
            'channel_limit' => 3,
            'video_limit'   => 20,
        ]));
        $this->paidMonthlyPrice = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->paidPlan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 12.99,
        ]));
    }

    /**
     * 註冊不再建立任何訂閱——免費月改成在第一次結帳時給。
     */
    public function testRegistrationCreatesNoSubscription(): void
    {
        $user = User::factory()->create();

        $this->assertEquals(0, $user->subscriptions()->count());
    }

    /** 沒有訂閱的新會員直接落到免費方案。 */
    public function testNewUserFallsBackToTheFreePlan(): void
    {
        $user = User::factory()->create();

        $service = new SubscriptionService();
        $subscription = $service->getUserSubscription($user->id);

        $this->assertNull($subscription);
        $this->assertEquals($this->freePlan->id, $service->getUserSubscriptionPlan($subscription)->id);
    }

    public function testNewUserIsEligibleForTheFreeMonth(): void
    {
        $user = User::factory()->create();

        $this->assertTrue((new SubscriptionService())->isEligibleForFreeMonth($user->id));
    }

    /** 結帳前先建好的那筆 paying 紀錄不算「訂閱過」——使用者可能中途放棄。 */
    public function testAnAbandonedCheckoutDoesNotConsumeTheFreeMonth(): void
    {
        $user = User::factory()->create();

        $this->subscriptionFor($user, Subscription::STATUS_PAYING);

        $this->assertTrue((new SubscriptionService())->isEligibleForFreeMonth($user->id));
    }

    public function testAnActiveSubscriptionConsumesTheFreeMonth(): void
    {
        $user = User::factory()->create();

        $this->subscriptionFor($user, Subscription::STATUS_ACTIVE);

        $this->assertFalse((new SubscriptionService())->isEligibleForFreeMonth($user->id));
    }

    /**
     * 取消之後再訂閱不能重領。Paddle 取消時我們的 status 不會被改寫，所以這裡
     * 同時要守住「留下過交易紀錄」這個判準。
     */
    public function testACanceledSubscriptionStillConsumesTheFreeMonth(): void
    {
        $user = User::factory()->create();

        $this->subscriptionFor($user, Subscription::STATUS_CANCELED);

        $this->assertFalse((new SubscriptionService())->isEligibleForFreeMonth($user->id));
    }

    public function testAPastTransactionConsumesTheFreeMonth(): void
    {
        $user = User::factory()->create();

        // status 停在 paying（Paddle 取消不會改寫狀態），但扣款發生過。
        $subscription = $this->subscriptionFor($user, Subscription::STATUS_PAYING);
        $subscription->transactions()->create([
            'billing_date' => now()->subMonths(3),
            'amount'       => 12.99,
            'status'       => 'completed',
        ]);

        $this->assertFalse((new SubscriptionService())->isEligibleForFreeMonth($user->id));
    }

    /** 軟刪除的紀錄一樣算數——訂閱成立過不會因為資料列被刪掉而沒發生過。 */
    public function testASoftDeletedSubscriptionStillConsumesTheFreeMonth(): void
    {
        $user = User::factory()->create();

        $this->subscriptionFor($user, Subscription::STATUS_ACTIVE)->delete();

        $this->assertFalse((new SubscriptionService())->isEligibleForFreeMonth($user->id));
    }

    /**
     * 2026-09 之前註冊送的那批試用訂閱是系統送的，不是使用者買的——不該吃掉
     * 他的首月免費。
     */
    public function testALegacySignupTrialDoesNotConsumeTheFreeMonth(): void
    {
        $user = User::factory()->create();

        $user->subscriptions()->create([
            'plan_id'        => $this->paidPlan->id,
            'price_id'       => $this->paidMonthlyPrice->id,
            'payment_method' => Subscription::PAYMENT_METHOD_TRIAL,
            'status'         => Subscription::STATUS_TRIAL,
            'start_date'     => now()->subDays(10),
            'next_date'      => now()->addDays(20),
        ]);

        $this->assertTrue((new SubscriptionService())->isEligibleForFreeMonth($user->id));
    }

    /** 免費月期間（status = trial）方案照樣生效，到期沒扣成才落回免費方案。 */
    public function testTheFreeMonthGrantsThePaidPlan(): void
    {
        $user = User::factory()->create();

        $subscription = $this->subscriptionFor($user, Subscription::STATUS_TRIAL);
        $subscription->fill(['next_date' => now()->addMonth()])->save();

        $service = new SubscriptionService();
        $this->assertEquals(
            $this->paidPlan->id,
            $service->getUserSubscriptionPlan($service->getUserSubscription($user->id))->id
        );

        $subscription->fill(['next_date' => now()->subSecond()])->save();

        $this->assertNull($service->getUserSubscription($user->id));
        $this->assertEquals(
            $this->freePlan->id,
            $service->getUserSubscriptionPlan($service->getUserSubscription($user->id))->id
        );
    }

    private function subscriptionFor(User $user, string $status): Subscription
    {
        return $user->subscriptions()->create([
            'plan_id'        => $this->paidPlan->id,
            'price_id'       => $this->paidMonthlyPrice->id,
            'payment_method' => Subscription::PAYMENT_METHOD_PADDLE,
            'status'         => $status,
            'start_date'     => now(),
        ]);
    }
}
