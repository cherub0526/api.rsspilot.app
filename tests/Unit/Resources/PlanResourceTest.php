<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use Tests\TestCase;
use App\Models\Plan;
use App\Http\Resources\PlanResource;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class PlanResourceTest extends TestCase
{
    use RefreshDatabase;

    public function testSkillEnabledIsABoolean(): void
    {
        config(['features.channel_skill' => true]);

        $enabled = Plan::withoutEvents(fn () => Plan::factory()->create(['skill_enabled' => true]));
        $default = Plan::withoutEvents(fn () => Plan::factory()->create());

        $this->assertTrue((new PlanResource(Plan::find($enabled->id)))->resolve()['skill_enabled']);
        $this->assertFalse((new PlanResource(Plan::find($default->id)))->resolve()['skill_enabled']);
    }

    /**
     * 回給前端的是「實際給不給」：頻道 Skill 全站關閉時，方案欄位是 true 也回
     * false，定價頁的權益清單才不會列出一個打了會 404 的功能。
     */
    public function testSkillEnabledIsFalseWhileTheFeatureIsOff(): void
    {
        config(['features.channel_skill' => false]);

        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['skill_enabled' => true]));

        $this->assertFalse((new PlanResource(Plan::find($plan->id)))->resolve()['skill_enabled']);
    }
}
