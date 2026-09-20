<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Services\PaddleSubscriptionService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 首月免費在 Paddle 這條路上的判斷。
 *
 * price 上帶著一個月的 trial_period（`paddle:sync` 設的），對每個結帳的人都一樣；
 * 「只送第一次」這條規則要靠 applyFreeMonth() 把沒資格的人當場 activate 來守住。
 *
 * 只測「要做什麼」這個判斷。真正的 API 呼叫測不到——PaddleClient 一律自己
 * new 出 Paddle\SDK\Client、不經容器解析（見 PaddleControllerTest 的說明），
 * 而判斷抽成 freeMonthAction() 就是為了讓這部分仍然有測試守著。
 *
 * @internal
 * @coversNothing
 */
class PaddleFreeMonthTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Price $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::withoutEvents(fn () => Plan::factory()->create(['title' => 'Pro']));
        $this->price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 12.99,
        ]));
    }

    /** 第一次訂閱的人留著 Paddle 給的免費月，一行 API 都不用呼叫。 */
    public function testKeepsTheFreeMonthForAFirstSubscription(): void
    {
        $service = new PaddleSubscriptionService();

        $this->assertSame(
            PaddleSubscriptionService::FREE_MONTH_ACTION_KEEP,
            $service->freeMonthAction('trialing', true)
        );
    }

    /** 用掉過的人不能白拿 price 上那段固定試用期——立刻啟用計費。 */
    public function testActivatesImmediatelyWhenTheFreeMonthIsUsedUp(): void
    {
        $service = new PaddleSubscriptionService();

        $this->assertSame(
            PaddleSubscriptionService::FREE_MONTH_ACTION_ACTIVATE,
            $service->freeMonthAction('trialing', false)
        );
    }

    /**
     * 價格沒設 trial_period 時 Paddle 回的是 active，這時什麼都不該做——包含不要
     * 去呼叫 activate，那會對一筆已經在計費的訂閱做多餘的動作。
     */
    public function testDoesNothingWhenThePaddleSubscriptionIsNotTrialing(): void
    {
        $service = new PaddleSubscriptionService();

        $this->assertSame(
            PaddleSubscriptionService::FREE_MONTH_ACTION_NONE,
            $service->freeMonthAction('active', true)
        );
        $this->assertSame(
            PaddleSubscriptionService::FREE_MONTH_ACTION_NONE,
            $service->freeMonthAction('past_due', false)
        );
    }

    /**
     * 結帳當下那筆 paying 紀錄是 SubscriptionsController::store() 先建好的，
     * 不能讓它把自己的資格吃掉。
     */
    public function testTheCheckoutsOwnRecordDoesNotConsumeTheFreeMonth(): void
    {
        $user = User::factory()->create();

        $pending = $user->subscriptions()->create([
            'plan_id'        => $this->plan->id,
            'price_id'       => $this->price->id,
            'payment_method' => Subscription::PAYMENT_METHOD_PADDLE,
            'status'         => Subscription::STATUS_PAYING,
        ]);

        $service = new PaddleSubscriptionService();

        $this->assertSame(
            PaddleSubscriptionService::FREE_MONTH_ACTION_KEEP,
            $service->freeMonthAction(
                'trialing',
                (new SubscriptionService())->isEligibleForFreeMonth(
                    (string) $user->id,
                    (string) $pending->getKey()
                )
            )
        );
    }
}
