<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Config;
use App\Utils\AI\RoutingProfile;
use App\Utils\AI\NeuronChatStreamer;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 解析順序是「方案覆寫用途」，而且只有 per-user 的路徑會帶使用者進來。
 *
 * @internal
 * @coversNothing
 */
class RoutingProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PURPOSE = NeuronChatStreamer::class;

    private const PURPOSE_KEY = 'App/Utils/AI/NeuronChatStreamer';

    protected function setUp(): void
    {
        parent::setUp();

        Config::setValue(Config::KEY_OPENROUTER_MODELS, [self::PURPOSE_KEY => 'openrouter/auto']);
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [
            self::PURPOSE_KEY => ['plugins' => [['id' => 'auto-router', 'cost_tier' => 'low']]],
        ]);
    }

    /**
     * 沒有訂閱的使用者會退回「月費 0 元」的方案，所以這個 plan 就是他們的方案。
     */
    private function planWithRouting(?array $routing): Plan
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'      => 'Free',
            'ai_routing' => $routing,
            'status'     => Plan::STATUS_ACTIVE,
        ]));

        Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 0,
        ]));

        return $plan;
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    // ================================================================

    public function testPurposeLevelIsUsedWhenThereIsNoUser(): void
    {
        $profile = RoutingProfile::for(self::PURPOSE);

        $this->assertSame('openrouter/auto', $profile->model);
        $this->assertSame([['id' => 'auto-router', 'cost_tier' => 'low']], $profile->parameters['plugins']);
    }

    /**
     * 共用產物（摘要、心智圖）走這條，永遠不看方案。
     */
    public function testForPurposeIgnoresAnyPlan(): void
    {
        $this->planWithRouting(['model' => 'openrouter/free']);

        $this->assertSame('openrouter/auto', RoutingProfile::forPurpose(self::PURPOSE)->model);
    }

    public function testPlanRoutingOverridesThePurpose(): void
    {
        $this->planWithRouting([
            'model'    => 'openrouter/auto',
            'plugins'  => [['id' => 'auto-router', 'cost_tier' => 'medium']],
            'provider' => ['max_price' => ['prompt' => 1.5, 'completion' => 5]],
        ]);

        $profile = RoutingProfile::for(self::PURPOSE, $this->user());

        $this->assertSame('openrouter/auto', $profile->model);
        $this->assertSame([['id' => 'auto-router', 'cost_tier' => 'medium']], $profile->parameters['plugins']);
        $this->assertSame(['max_price' => ['prompt' => 1.5, 'completion' => 5]], $profile->parameters['provider']);
    }

    /**
     * Free 的 profile 只有 model：openrouter/free 不吃 auto-router 的 plugin，
     * 用途層那組 cost_tier 不該被一起帶過去。
     */
    public function testAModelOnlyProfileDropsThePurposeParameters(): void
    {
        $this->planWithRouting(['model' => 'openrouter/free']);

        $profile = RoutingProfile::for(self::PURPOSE, $this->user());

        $this->assertSame('openrouter/free', $profile->model);
        $this->assertSame([], $profile->parameters);
    }

    /**
     * profile 只給參數時沿用用途層的模型。
     */
    public function testAProfileWithoutAModelKeepsThePurposeModel(): void
    {
        $this->planWithRouting(['plugins' => [['id' => 'auto-router', 'cost_tier' => 'max']]]);

        $profile = RoutingProfile::for(self::PURPOSE, $this->user());

        $this->assertSame('openrouter/auto', $profile->model);
        $this->assertSame([['id' => 'auto-router', 'cost_tier' => 'max']], $profile->parameters['plugins']);
    }

    /**
     * 方案沒設定就退回用途層，不是變成空的。
     */
    public function testANullRoutingFallsBackToThePurpose(): void
    {
        $this->planWithRouting(null);

        $profile = RoutingProfile::for(self::PURPOSE, $this->user());

        $this->assertSame('openrouter/auto', $profile->model);
        $this->assertSame([['id' => 'auto-router', 'cost_tier' => 'low']], $profile->parameters['plugins']);
    }

    public function testEqualsComparesBothModelAndParameters(): void
    {
        $a = new RoutingProfile('openrouter/auto', ['plugins' => [['id' => 'auto-router']]]);
        $b = new RoutingProfile('openrouter/auto', ['plugins' => [['id' => 'auto-router']]]);
        $c = new RoutingProfile('openrouter/auto');
        $d = new RoutingProfile('openrouter/free', ['plugins' => [['id' => 'auto-router']]]);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
        $this->assertFalse($a->equals($d));
    }
}
