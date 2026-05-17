<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Plan;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class PlanFeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function testPlanHasFeatureFlagColumns(): void
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'download_enabled'       => true,
            'agent_enabled'          => false,
            'advanced_model_enabled' => true,
            'custom_summary_enabled' => false,
        ]));

        $this->assertDatabaseHas('plans', [
            'id'                     => $plan->id,
            'download_enabled'       => 1,
            'agent_enabled'          => 0,
            'advanced_model_enabled' => 1,
            'custom_summary_enabled' => 0,
        ]);
    }

    public function testFeatureFlagsDefaultToFalse(): void
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create());

        $fresh = Plan::find($plan->id);
        $this->assertFalse($fresh->download_enabled);
        $this->assertFalse($fresh->agent_enabled);
        $this->assertFalse($fresh->advanced_model_enabled);
        $this->assertFalse($fresh->custom_summary_enabled);
    }
}
