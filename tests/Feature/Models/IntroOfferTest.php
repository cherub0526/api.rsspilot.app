<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Creem;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Services\CreemSubscriptionService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 新客優惠：免費試用 14 天（月繳與年繳都適用），以及選用的首期折價那條路。
 *
 * 四件事要守住，重要性由上而下：
 *
 * 1. **試用長度真的是 `Subscription::TRIAL_DAYS` 天，而且月繳與年繳都有。**
 *    這是 RSS-3 唯一的營收意義：第一筆扣款從 +30 天拉到 +14 天
 * 2. **終生一次**——「訂閱 → 取消 → 再訂閱」拿不到第二次，跨週期也擋得住
 * 3. **試用與首期折價互斥**。`first_period_price` 是 null（目前全部如此）時
 *    完全不送折扣碼；填了值則改走折價、不送試用。同時成立就是一個人吃兩份
 * 4. `first_period_price` 只有年繳認，而且填錯的值要當成沒有優惠
 *
 * 第 2 點的另一半（各種訂閱狀態怎麼吃掉資格）在 TrialEligibilityTest；這裡測的是
 * 年繳與跨週期走的是不是同一個判斷。
 *
 * @internal
 * @coversNothing
 */
class IntroOfferTest extends TestCase
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

        // 正式的 Pro 年繳：$129，**沒有**首期折價（owner 2026-10-13 決定不降價）。
        $this->annualPrice = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->paidPlan->id,
            'unit'    => Price::UNIT_ANNUALLY,
            'price'   => 129,
        ]));
    }

    /**
     * 試用長度是 14 天。
     *
     * 這條看起來像在測一個常數，但它守的是「14」這個數字本身：三家金流與三語系
     * 文案全部從這裡推導（Creem 的 `trial_period_days`、Stripe 的 `trial_end`、
     * Paddle 的 `trial_period`、以及 API 吐給前端的 `prices[].trial_days`），
     * 所以改掉它會讓這條紅，提醒去看對外文案與金流目錄要不要跟著動。
     */
    public function testTheTrialIsFourteenDays(): void
    {
        $this->assertSame(14, Subscription::TRIAL_DAYS);
    }

    /**
     * **月繳與年繳都送試用**，而且天數一樣。
     *
     * 年繳曾經一度完全不送（改走首年折價），所以這條是回歸測試：年繳少了試用，
     * 對外承諾的「免費試用 14 天」就有一半是假的。
     */
    public function testBothBillingPeriodsOfferTheSameTrial(): void
    {
        $this->assertSame(Subscription::TRIAL_DAYS, $this->monthlyPrice->trialDays());
        $this->assertSame(Subscription::TRIAL_DAYS, $this->annualPrice->trialDays());
    }

    /** 免費方案不送試用——$0 掛試用期沒有意義。 */
    public function testTheFreePlanHasNoTrial(): void
    {
        $free = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $this->paidPlan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 0,
        ]));

        $this->assertNull($free->trialDays());
    }

    /**
     * 有資格就送試用，月繳與年繳都是；沒資格就不送。
     *
     * `grantsTrial()` 是結帳時決定「這個人要被送去含試用的 product 還是不含的」
     * 的那一行，挑錯的後果是白送 14 天或當場多收一期的錢。
     */
    public function testEligibleCheckoutsGetATrialOnBothPeriods(): void
    {
        $service = new CreemSubscriptionService();

        $this->assertTrue($service->grantsTrial($this->monthlyPrice, true));
        $this->assertTrue($service->grantsTrial($this->annualPrice, true));

        $this->assertFalse($service->grantsTrial($this->monthlyPrice, false));
        $this->assertFalse($service->grantsTrial($this->annualPrice, false));
    }

    /**
     * 設了首期折價的 price **不送試用**，連有資格的人也一樣。
     *
     * 兩種優惠互斥。不擋的話同一筆年繳結帳會同時拿到 14 天免費與首年折價，
     * 而且那是從 `first_period_price` 這個欄位看不出來的。
     */
    public function testAPriceWithAnIntroDiscountGrantsNoTrial(): void
    {
        $this->annualPrice->fill(['first_period_price' => 99])->save();

        $service = new CreemSubscriptionService();

        $this->assertFalse(
            $service->grantsTrial($this->annualPrice, true),
            '走折價那條路的 price 不該同時送試用'
        );
        $this->assertNull(
            $this->annualPrice->trialDays(),
            'trial_days 要跟著回 null，不然定價頁會同時講兩種優惠'
        );
    }

    /**
     * 結帳挑的是含試用的 product，月繳與年繳都是。
     *
     * 年繳的 trial variant 是 2026-10 才加回來的，所以這條同時在守「`creem:sync`
     * 真的為年繳建了它」這個前提——沒有的話 `productIdFor()` 會拋例外，
     * 使用者看到的是結帳失敗。
     */
    public function testCheckoutPicksTheTrialProductForBothPeriods(): void
    {
        foreach ([$this->monthlyPrice, $this->annualPrice] as $price) {
            $price->creem()->create([
                'foreign_type' => Price::class,
                'creem_id'     => "prod_{$price->unit}_standard",
                'variant'      => Creem::VARIANT_STANDARD,
            ]);
            $price->creem()->create([
                'foreign_type' => Price::class,
                'creem_id'     => "prod_{$price->unit}_trial",
                'variant'      => Creem::VARIANT_TRIAL,
            ]);
        }

        $service = new CreemSubscriptionService();

        foreach ([$this->monthlyPrice, $this->annualPrice] as $price) {
            $withTrial = $service->grantsTrial($price, true);

            $this->assertSame(
                "prod_{$price->unit}_trial",
                $service->productIdFor($price, $withTrial),
                "{$price->unit} 有資格時應該挑含試用的 product"
            );
            $this->assertSame(
                "prod_{$price->unit}_standard",
                $service->productIdFor($price, false),
                "{$price->unit} 沒資格時應該挑不含試用的 product"
            );
        }
    }

    /** 新帳號還有新客優惠。 */
    public function testANewAccountIsEligibleForTheIntroOffer(): void
    {
        $user = User::factory()->create();

        $this->assertTrue((new SubscriptionService())->isEligibleForIntroOffer($user->id));
    }

    /**
     * 年繳 → 取消 → 再訂閱拿不到第二個 14 天。
     *
     * 這是任務 RSS-3 明文要守的那條。取消時我們自己的 `status` 不一定會被改寫，
     * 所以判準是「成立過」而不是「現在有沒有在訂」——下面兩個狀態都要擋。
     */
    public function testResubscribingAfterCancellationGetsNoSecondTrial(): void
    {
        $service = new SubscriptionService();
        $creem = new CreemSubscriptionService();

        foreach ([Subscription::STATUS_ACTIVE, Subscription::STATUS_CANCELED] as $status) {
            $user = User::factory()->create();
            $this->assertTrue($service->isEligibleForIntroOffer($user->id));

            $this->annualSubscriptionFor($user, $status);

            $eligible = $service->isEligibleForIntroOffer($user->id);

            $this->assertFalse($eligible, "狀態 {$status} 的年繳訂閱應該吃掉新客優惠資格");
            // 資格與「實際送不送試用」是兩回事，兩邊都要擋到——沒資格的人若仍被
            // 送去含試用的 product，就是無限續杯。
            $this->assertFalse($creem->grantsTrial($this->annualPrice, $eligible));
            $this->assertFalse($creem->grantsTrial($this->monthlyPrice, $eligible));
        }
    }

    /**
     * 一個閘門兩種週期：月繳用掉試用之後，改訂年繳也領不到。
     *
     * 分開計算的話這就是一條「一個人吃兩份」的路徑，而且是最好走的那一條
     * ——月繳的試用不必付錢就能用掉。
     */
    public function testUsingTheTrialOnMonthlyAlsoConsumesItForAnnual(): void
    {
        $user = User::factory()->create();

        $user->subscriptions()->create([
            'plan_id'        => $this->paidPlan->id,
            'price_id'       => $this->monthlyPrice->id,
            'payment_method' => Subscription::PAYMENT_METHOD_CREEM,
            'status'         => Subscription::STATUS_ACTIVE,
            'start_date'     => now()->subMonths(2),
        ]);

        $this->assertFalse((new SubscriptionService())->isEligibleForIntroOffer($user->id));
    }

    /** 中途放棄的結帳不算訂閱過，資格要留著。 */
    public function testAnAbandonedAnnualCheckoutKeepsTheIntroOffer(): void
    {
        $user = User::factory()->create();

        $this->annualSubscriptionFor($user, Subscription::STATUS_PAYING);

        $this->assertTrue((new SubscriptionService())->isEligibleForIntroOffer($user->id));
    }

    /**
     * `first_period_price` 是 null → **完全不發折扣碼**，也不會對 Creem 發任何請求。
     *
     * 這就是目前所有 price 的狀態。沒這一關的話 null 會一路走到 createDiscount
     * 去建一張金額是 0 或負數的折扣碼；而因為這條路徑沒有網路往返，這裡可以直接
     * 呼叫——它要是真的打了 Creem，測試會因為沒設定 CREEM_API_KEY 而炸掉。
     */
    public function testNoDiscountCodeIsIssuedWithoutAnIntroPrice(): void
    {
        $user = User::factory()->create();
        $subscription = $this->annualSubscriptionFor($user, Subscription::STATUS_PAYING);
        $service = new CreemSubscriptionService();

        foreach ([$this->annualPrice, $this->monthlyPrice] as $price) {
            $this->assertNull($price->introDiscountCents());
            $this->assertNull(
                $service->introDiscountCodeFor($price, $subscription),
                "{$price->unit} 沒設首期優惠價時不該發折扣碼"
            );
        }
    }

    /** 首年 $99、續約 $129 → 折 3,000 美分。 */
    public function testAnnualPriceExposesItsFirstYearDiscount(): void
    {
        $this->annualPrice->fill(['first_period_price' => 99])->save();

        $this->assertTrue($this->annualPrice->isAnnual());
        $this->assertEquals(99.0, $this->annualPrice->firstPeriodPrice());
        $this->assertEquals(3000, $this->annualPrice->introDiscountCents());
    }

    /**
     * 月繳**不**認 `first_period_price`。
     *
     * 只有年繳有「首期折價」這個概念；月繳填了值只會讓它悄悄失去試用期
     * （`grantsTrial()` 問的是 `introDiscountCents()`），所以這裡要回 null。
     */
    public function testMonthlyPricesIgnoreTheIntroPrice(): void
    {
        $this->monthlyPrice->fill(['first_period_price' => 9.99])->save();

        $this->assertNull($this->monthlyPrice->firstPeriodPrice());
        $this->assertNull($this->monthlyPrice->introDiscountCents());
        $this->assertSame(
            Subscription::TRIAL_DAYS,
            $this->monthlyPrice->trialDays(),
            '月繳填錯這個欄位不該連試用期一起弄掉'
        );
    }

    /**
     * 填錯的優惠價要當成沒有優惠，不能靜靜生效。
     *
     * >= 原價是「折了負的」，<= 0 是免費送一年。兩種都寧可當作沒設——而且因為
     * 「有折價」會關掉試用期，一個填錯的值不該把試用也一起弄掉。
     */
    public function testAnInvalidIntroPriceIsTreatedAsNoOffer(): void
    {
        foreach ([129, 150, 0, -5] as $bad) {
            $this->annualPrice->fill(['first_period_price' => $bad])->save();

            $this->assertNull(
                $this->annualPrice->firstPeriodPrice(),
                "first_period_price = {$bad} 不該被當成有效的優惠價"
            );
            $this->assertSame(
                Subscription::TRIAL_DAYS,
                $this->annualPrice->trialDays(),
                "first_period_price = {$bad} 不該連試用期一起弄掉"
            );
        }
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
