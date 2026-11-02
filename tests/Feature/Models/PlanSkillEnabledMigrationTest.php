<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Plan;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * channel-skill：正式環境的方案列不會因為改 seeder 而變，`skill_enabled` 要靠 migration 補上
 * （見 docs/lore/subscription/business-rules.md〈改 seeder 的方案數值一定要附 migration〉）。
 *
 * RefreshDatabase 跑 migration 時 plans 是空的，所以這裡先建好既有方案，
 * 再把該支 migration down → up 一次，模擬它在已有資料的資料庫上執行。
 *
 * @internal
 * @coversNothing
 */
class PlanSkillEnabledMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function testMigrationEnablesSkillForExistingProAndAdvancePlans(): void
    {
        $files = glob(database_path('migrations/*_add_skill_enabled_to_plans_table.php')) ?: [];
        $this->assertCount(1, $files, 'expected exactly one *_add_skill_enabled_to_plans_table migration');

        $migration = require $files[0];
        $migration->down();

        $ids = Plan::withoutEvents(fn () => [
            'Free'    => Plan::factory()->create(['title' => 'Free'])->id,
            'Pro'     => Plan::factory()->create(['title' => 'Pro'])->id,
            'Advance' => Plan::factory()->create(['title' => 'Advance'])->id,
        ]);

        $migration->up();

        $this->assertFalse((bool) Plan::find($ids['Free'])->skill_enabled);
        $this->assertTrue((bool) Plan::find($ids['Pro'])->skill_enabled);
        $this->assertTrue((bool) Plan::find($ids['Advance'])->skill_enabled);
    }

    public function testSkillEnabledDefaultsToFalseForNewPlans(): void
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create());

        $this->assertFalse((bool) Plan::find($plan->id)->skill_enabled);
    }
}
