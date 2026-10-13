<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Services\CreemSubscriptionService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 年繳的首年折價。
 *
 * 兩件事要守住，而且第二件比第一件重要：
 *
 * 1. `first_period_price` 只有年繳認，而且填錯的值要當成沒有優惠
 * 2. **折價與首月免費共用同一個終生一次的閘門**——「訂閱 → 取消 → 再訂閱」拿不到
 *    第二次，「月繳用掉免費月 → 改訂年繳」也拿不到折價
 *
 * 第二件在 FreeMonthEligibilityTest 已經測過首月免費那一半；這裡測的是年繳這一半
 * 走的是不是同一個判斷，以及跨週期也擋得住。
 *
 * @internal
 * @coversNothing
 */
class AnnualIntroOfferTest extends TestCase
{
    use RefreshDatabase;

    private Plan $paidPlan;

    private Price $monthlyPrice;

    private Price $annualPrice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paidPlan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'         => 'Pro',
            'channel_limit' => 3,
            'video_limit'   => 20,
        ]));

        $this->monthlyPrice = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->paidPlan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 12.99,
        ]));

        // 正式的 Pro 年繳：續約 $129，首年 $99。
        $this->annualPrice = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id'            => $this->paidPlan->id,
            'unit'               => Price::UNIT_ANNUALLY,
            'price'              => 129,
            'first_period_price' => 99,
        ]));
    }

    /** 首年 $99、續約 $129 → 折 3,000 美分。 */
    public function testAnnualPriceExposesItsFirstYearDiscount(): void
    {
        $this->assertTrue($this->annualPrice->isAnnual());
        $this->assertEquals(99.0, $this->annualPrice->firstPeriodPrice());
        $this->assertEquals(3000, $this->annualPrice->introDiscountCents());
    }

    /** 沒設優惠價的年繳就是沒有優惠，不是折 0 元。 */
    public function testAnAnnualPriceWithoutAnIntroPriceHasNoDiscount(): void
    {
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->paidPlan->id,
            'unit'    => Price::UNIT_ANNUALLY,
            'price'   => 249,
        ]));

        $this->assertNull($price->firstPeriodPrice());
        $this->assertNull($price->introDiscountCents());
    }

    /**
     * 月繳**不**認 `first_period_price`。
     *
     * 月繳的新客優惠是首月免費；兩個疊起來等於同一個人一次拿兩份。
     */
    public function testMonthlyPricesIgnoreTheIntroPrice(): void
    {
        $this->monthlyPrice->fill(['first_period_price' => 9.99])->save();

        $this->assertNull($this->monthlyPrice->firstPeriodPrice());
        $this->assertNull($this->monthlyPrice->introDiscountCents());
    }

    /**
     * 填錯的優惠價要當成沒有優惠，不能靜靜生效。
     *
     * >= 原價是「折了負的」，<= 0 是免費送一年。兩種都寧可當作沒設。
     */
    public function testAnInvalidIntroPriceIsTreatedAsNoOffer(): void
    {
        foreach ([129, 150, 0, -5] as $bad) {
            $this->annualPrice->fill(['first_period_price' => $bad])->save();

            $this->assertNull(
                $this->annualPrice->firstPeriodPrice(),
                "first_period_price = {$bad} 不該被當成有效的優惠價"
            );
        }
    }

    /** 新帳號兩種優惠都還在。 */
    public function testANewAccountIsEligibleForTheIntroOffer(): void
    {
        $user = User::factory()->create();

        $this->assertTrue((new SubscriptionService())->isEligibleForIntroOffer($user->id));
    }

    /**
     * 年繳 → 取消 → 再訂閱拿不到第二次折價。
     *
     * 這是任務 RSS-3 明文要守的那條。取消時我們自己的 `status` 不一定會被改寫，
     * 所以判準是「成立過」而不是「現在有沒有在訂」——下面兩個狀態都要擋。
     */
    public function testResubscribingAfterCancellationGetsNoSecondIntroOffer(): void
    {
        $service = new SubscriptionService();

        foreach ([Subscription::STATUS_ACTIVE, Subscription::STATUS_CANCELED] as $status) {
            $user = User::factory()->create();
            $this->assertTrue($service->isEligibleForIntroOffer($user->id));

            $this->annualSubscriptionFor($user, $status);

            $this->assertFalse(
                $service->isEligibleForIntroOffer($user->id),
                "狀態 {$status} 的年繳訂閱應該吃掉新客優惠資格"
            );
        }
    }

    /**
     * 兩種優惠共用一個閘門：月繳用掉首月免費之後，改訂年繳也領不到折價。
     *
     * 分開計算的話這就是一條「一個人吃兩份」的路徑，而且是最好走的那一條
     * ——月繳首月免費不必付錢就能用掉。
     */
    public function testUsingTheFreeMonthOnMonthlyAlsoConsumesTheAnnualDiscount(): void
    {
        $user = User::factory()->create();

        $user->subscriptions()->create([
            'plan_id'        => $this->paidPlan->id,
            'price_id'       => $this->monthlyPrice->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
            'status'         => Subscription::STATUS_ACTIVE,
            'start_date'     => now()->subMonths(2),
        ]);

        $service = new SubscriptionService();

        $this->assertFalse($service->isEligibleForFreeMonth($user->id));
        $this->assertFalse($service->isEligibleForIntroOffer($user->id));
    }

    /** 中途放棄的結帳不算訂閱過，資格要留著。 */
    public function testAnAbandonedAnnualCheckoutKeepsTheIntroOffer(): void
    {
        $user = User::factory()->create();

        $this->annualSubscriptionFor($user, Subscription::STATUS_PAYING);

        $this->assertTrue((new SubscriptionService())->isEligibleForIntroOffer($user->id));
    }

    /**
     * 沒有首年優惠的年繳不會去 Creem 建折扣碼。
     *
     * 這條沒有網路往返，所以可以在測試裡直接呼叫——回 null 代表 `createCheckout`
     * 不會帶 `discount_code`，也不會因為沒設定 CREEM_API_KEY 而炸掉。
     */
    public function testNoDiscountCodeIsIssuedWithoutAnIntroPrice(): void
    {
        $user = User::factory()->create();
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->paidPlan->id,
            'unit'    => Price::UNIT_ANNUALLY,
            'price'   => 249,
        ]));

        $subscription = $this->annualSubscriptionFor($user, Subscription::STATUS_PAYING);

        $this->assertNull((new CreemSubscriptionService())->introDiscountCodeFor($price, $subscription));
    }

    /**
     * 折扣碼由訂閱 id 推導：同一筆訂閱穩定不變（重試結帳沿用同一張），
     * 不同訂閱一定不同（一張碼只能兌換一次）。
     */
    public function testTheIntroCodeIsStablePerSubscriptionAndUniqueAcrossThem(): void
    {
        $user = User::factory()->create();
        $a = $this->annualSubscriptionFor($user, Subscription::STATUS_PAYING);
        $b = $this->annualSubscriptionFor($user, Subscription::STATUS_PAYING);

        $codeA = CreemSubscriptionService::introCodeFor($a);

        $this->assertSame($codeA, CreemSubscriptionService::introCodeFor($a));
        $this->assertNotSame($codeA, CreemSubscriptionService::introCodeFor($b));
        $this->assertStringStartsWith('INTRO', $codeA);
        // 碼不能是訂閱 id 的明文：能列舉就等於能繞過資格閘門。
        $this->assertStringNotContainsString((string) $a->getKey(), $codeA);
    }

    private function annualSubscriptionFor(User $user, string $status): Subscription
    {
        return $user->subscriptions()->create([
            'plan_id'        => $this->paidPlan->id,
            'price_id'       => $this->annualPrice->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
            'status'         => $status,
            'start_date'     => now(),
        ]);
    }
}
