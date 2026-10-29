<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Concerns;

use Mockery;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use Hypervel\Http\Request;
use App\Models\Subscription;
use Hypervel\Support\Facades\App;
use App\Exceptions\InvalidRequestException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Http\Controllers\Concerns\ResolvesUserPlan;

/**
 * channel-skill S06 / S39 的閘門：assertSkillEnabled() 依 plans.skill_enabled 判斷，
 * 未開通丟 422 `errors.plan`。下載端點（T06）會走同一道閘門，HTTP 層的 S06 / S39 在那時補上。
 *
 * @internal
 * @coversNothing
 */
class AssertSkillEnabledTest extends TestCase
{
    use RefreshDatabase;

    /** 沒有訂閱時退回「月費 0」的方案，所以把預設方案建成那樣並決定它開不開通。 */
    private function defaultPlan(bool $skillEnabled): void
    {
        Plan::withoutEvents(function () use ($skillEnabled) {
            $plan = Plan::factory()->create(['skill_enabled' => $skillEnabled, 'sort' => 0]);
            // withoutEvents 也關掉了 HasUlids 的 creating callback，所以 id 交給 factory 給。
            Price::withoutEvents(fn () => Price::factory()->create([
                'plan_id' => $plan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 0,
            ]));
        });
    }

    /** 付費方案（月費不為 0），回傳 [plan, price]。 */
    private function paidPlan(bool $skillEnabled): array
    {
        return Plan::withoutEvents(function () use ($skillEnabled) {
            $plan = Plan::factory()->create(['skill_enabled' => $skillEnabled, 'sort' => 1]);
            $price = Price::withoutEvents(fn () => Price::factory()->create([
                'plan_id' => $plan->id,
                'unit'    => Price::UNIT_MONTHLY,
                'price'   => 12.99,
            ]));

            return [$plan, $price];
        });
    }

    private function subscribe(User $user, Plan $plan, Price $price, string $status): void
    {
        Subscription::factory()->create([
            'user_id'    => $user->id,
            'plan_id'    => $plan->id,
            'price_id'   => $price->id,
            'status'     => $status,
            'start_date' => now()->subDay(),
        ]);
    }

    private function check(?User $user = null): void
    {
        $user ??= User::factory()->create();
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user);

        $gate = new class {
            use ResolvesUserPlan;

            public function run(Request $request): void
            {
                $this->assertSkillEnabled($request);
            }
        };

        $gate->run($request);
    }

    public function testS06PassesWhenPlanHasSkillEnabled(): void
    {
        $this->defaultPlan(true);

        $this->check();

        $this->addToAssertionCount(1);
    }

    public function testS06RejectsPlanWithoutSkillWithPlanError(): void
    {
        $this->defaultPlan(false);

        try {
            $this->check();
            $this->fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            // render() 需要 HTTP context；狀態碼由 exception code 帶出。
            $this->assertSame(422, $e->getCode());
            $this->assertArrayHasKey('plan', $e->messages);
        }
    }

    /**
     * 判斷走「使用者當下生效的方案」，不是預設方案：免費方案沒開、訂閱的 Pro 有開 → 放行。
     */
    public function testPassesForAnActiveSubscriptionToASkillPlan(): void
    {
        $this->defaultPlan(false);
        [$plan, $price] = $this->paidPlan(true);
        $user = User::factory()->create();
        $this->subscribe($user, $plan, $price, Subscription::STATUS_ACTIVE);

        $this->check($user);

        $this->addToAssertionCount(1);
    }

    /**
     * S39：訂閱已取消（降回免費）時立即失去權限，不因為曾經是 Pro 而放行。
     */
    public function testS39CanceledSubscriptionFallsBackToFreeAndIsRejected(): void
    {
        $this->defaultPlan(false);
        [$plan, $price] = $this->paidPlan(true);
        $user = User::factory()->create();
        $this->subscribe($user, $plan, $price, Subscription::STATUS_CANCELED);

        $this->expectException(InvalidRequestException::class);

        $this->check($user);
    }

    /**
     * 有效訂閱的方案本身沒開 skill（例如未來的中間方案）→ 擋下，即使有其他方案開著。
     */
    public function testRejectsActiveSubscriptionToAPlanWithoutSkill(): void
    {
        $this->defaultPlan(false);
        [$plan, $price] = $this->paidPlan(false);
        $this->paidPlan(true);
        $user = User::factory()->create();
        $this->subscribe($user, $plan, $price, Subscription::STATUS_ACTIVE);

        $this->expectException(InvalidRequestException::class);

        $this->check($user);
    }

    public function testS06RejectsWhenNoPlanCanBeResolved(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->check();
    }

    /**
     * 錯誤訊息三語都要有翻譯，不能把 key 原樣吐給使用者。
     */
    public function testPlanRequiredMessageIsTranslatedInAllLocales(): void
    {
        $key = 'validators.controllers.source_skill.plan_required';

        foreach (['en', 'zh-TW', 'zh-CN'] as $locale) {
            App::setLocale($locale);
            $this->assertNotSame($key, __($key), "missing {$key} in {$locale}");
        }
    }
}
