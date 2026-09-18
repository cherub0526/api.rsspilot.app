<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\PaddleSubscriptionService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 試用中在 Paddle 結帳時，首次扣款要落在我們的試用結束日。
 *
 * 只測「要做什麼」這個判斷。真正的 API 呼叫測不到——PaddleClient 一律自己
 * new 出 Paddle\SDK\Client、不經容器解析（見 PaddleControllerTest 的說明），
 * 而判斷抽成 trialAction() 就是為了讓這部分仍然有測試守著。
 *
 * @internal
 * @coversNothing
 */
class PaddleTrialAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTrial(?Carbon $trialEnd): User
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['title' => 'Pro']));
        Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 12.99,
        ]));

        $user = User::factory()->create();

        // factory 建立時 observer 會送一份試用，這裡改成案例要的到期日。
        $user->subscriptions()->where('status', Subscription::STATUS_TRIAL)->delete();

        if ($trialEnd !== null) {
            $user->subscriptions()->create([
                'plan_id'        => $plan->id,
                'price_id'       => $plan->prices()->first()->id,
                'payment_method' => Subscription::PAYMENT_METHOD_TRIAL,
                'status'         => Subscription::STATUS_TRIAL,
                'start_date'     => now()->subDays(5),
                'next_date'      => $trialEnd,
            ]);
        }

        return $user;
    }

    public function testDefersBillingWhenTheTrialStillHasTimeLeft(): void
    {
        $trialEnd = now()->addDays(20);
        $user = $this->userWithTrial($trialEnd);

        $service = new PaddleSubscriptionService();

        $this->assertSame(
            PaddleSubscriptionService::TRIAL_ACTION_DEFER,
            $service->trialAction('trialing', $service->trialEndFor($user->id))
        );
        $this->assertSame(
            $trialEnd->toDateTimeString(),
            $service->trialEndFor($user->id)->toDateTimeString()
        );
    }

    /**
     * 沒有剩餘試用的人不能白拿 price 上那段固定試用期——立刻啟用計費。
     */
    public function testActivatesImmediatelyWhenThereIsNoTrialLeft(): void
    {
        $user = $this->userWithTrial(null);

        $service = new PaddleSubscriptionService();

        $this->assertNull($service->trialEndFor($user->id));
        $this->assertSame(
            PaddleSubscriptionService::TRIAL_ACTION_ACTIVATE,
            $service->trialAction('trialing', $service->trialEndFor($user->id))
        );
    }

    /** 過期的試用等同沒有試用。 */
    public function testExpiredTrialCountsAsNoTrial(): void
    {
        $user = $this->userWithTrial(now()->subDay());

        $service = new PaddleSubscriptionService();

        $this->assertNull($service->trialEndFor($user->id));
    }

    /**
     * Paddle 規定 next_billed_at 至少要 30 分鐘之後，剩得比這還少就沒得延。
     */
    public function testActivatesWhenLessThanThirtyMinutesRemain(): void
    {
        $service = new PaddleSubscriptionService();

        $this->assertSame(
            PaddleSubscriptionService::TRIAL_ACTION_ACTIVATE,
            $service->trialAction('trialing', now()->addMinutes(20))
        );
        $this->assertSame(
            PaddleSubscriptionService::TRIAL_ACTION_DEFER,
            $service->trialAction('trialing', now()->addMinutes(40))
        );
    }

    /**
     * 價格沒設試用期時 Paddle 回的是 active，這時什麼都不該做——包含不要去
     * 呼叫 activate，那會對一筆已經在計費的訂閱做多餘的動作。
     */
    public function testDoesNothingWhenThePaddleSubscriptionIsNotTrialing(): void
    {
        $service = new PaddleSubscriptionService();

        $this->assertSame(
            PaddleSubscriptionService::TRIAL_ACTION_NONE,
            $service->trialAction('active', now()->addDays(20))
        );
        $this->assertSame(
            PaddleSubscriptionService::TRIAL_ACTION_NONE,
            $service->trialAction('past_due', null)
        );
    }
}
