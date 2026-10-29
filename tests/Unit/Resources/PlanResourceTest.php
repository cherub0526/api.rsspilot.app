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
        $enabled = Plan::withoutEvents(fn () => Plan::factory()->create(['skill_enabled' => true]));
        $default = Plan::withoutEvents(fn () => Plan::factory()->create());

        $this->assertTrue((new PlanResource(Plan::find($enabled->id)))->resolve()['skill_enabled']);
        $this->assertFalse((new PlanResource(Plan::find($default->id)))->resolve()['skill_enabled']);
    }
}
