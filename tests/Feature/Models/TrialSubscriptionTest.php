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
 * @internal
 * @coversNothing
 */
class TrialSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Plan $trialPlan;

    private Price $trialMonthlyPrice;

    protected function setUp(): void
    {
        parent::setUp();

        // 註冊送的是 Pro 一個月（見 UserObserver::TRIAL_PLAN_TITLE）。
        $this->trialPlan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'         => 'Pro',
            'channel_limit' => 3,
            'video_limit'   => 20,
        ]));

        $this->trialMonthlyPrice = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->trialPlan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 12.99,
        ]));
    }

    public function testRegistrationCreatesTrial(): void
    {
        $user = User::factory()->create();

        $subscription = $user->subscriptions()->first();

        $this->assertNotNull($subscription);
        $this->assertEquals(Subscription::STATUS_TRIAL, $subscription->status);
        $this->assertEquals($this->trialPlan->id, $subscription->plan_id);
        $this->assertEquals($this->trialMonthlyPrice->id, $subscription->price_id);
        $this->assertEquals(Subscription::PAYMENT_METHOD_TRIAL, $subscription->payment_method);
        $this->assertNotNull($subscription->start_date);
        $this->assertNotNull($subscription->next_date);

        // 一個月，不是固定天數——月份長度不同，用 addMonth() 的結果來比。
        $this->assertEquals(
            now()->addMonth()->toDateString(),
            $subscription->next_date->toDateString()
        );
    }

    public function testTrialGrantsProPlanLimits(): void
    {
        $user = User::factory()->create();

        $service = new SubscriptionService();
        $subscription = $service->getUserSubscription($user->id);
        $plan = $service->getUserSubscriptionPlan($subscription);

        $this->assertNotNull($plan);
        $this->assertEquals($this->trialPlan->id, $plan->id);
        $this->assertEquals(3, $plan->channel_limit);
        $this->assertEquals(20, $plan->video_limit);
    }

    public function testExpiredTrialFallsBackToFreePlan(): void
    {
        // Create a free plan (fallback target)
        $freePlan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'         => 'Free',
            'channel_limit' => 1,
            'video_limit'   => 3,
        ]));
        Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $freePlan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 0,
        ]));

        $user = User::factory()->create();

        // Expire the trial
        $user->subscriptions()
            ->where('status', Subscription::STATUS_TRIAL)
            ->update(['next_date' => now()->subSecond()]);

        $service = new SubscriptionService();
        $subscription = $service->getUserSubscription($user->id);

        $this->assertNull($subscription, 'Expired trial should not be returned as active');

        $plan = $service->getUserSubscriptionPlan($subscription);
        $this->assertNotNull($plan);
        $this->assertEquals($freePlan->id, $plan->id);
    }

    public function testActiveTrialIsIncludedInScopeActive(): void
    {
        $user = User::factory()->create();

        $activeCount = Subscription::query()
            ->where('user_id', $user->id)
            ->active()
            ->count();

        $this->assertEquals(1, $activeCount);
    }

    public function testExpiredTrialExcludedFromScopeActive(): void
    {
        $user = User::factory()->create();

        $user->subscriptions()
            ->where('status', Subscription::STATUS_TRIAL)
            ->update(['next_date' => now()->subSecond()]);

        $activeCount = Subscription::query()
            ->where('user_id', $user->id)
            ->active()
            ->count();

        $this->assertEquals(0, $activeCount);
    }

    public function testNoTrialCreatedWhenTheTrialPlanIsMissing(): void
    {
        $this->trialPlan->forceDelete();

        $user = User::factory()->create();

        $this->assertEquals(0, $user->subscriptions()->count());
    }
}
