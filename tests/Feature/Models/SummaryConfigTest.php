<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use App\Models\SummaryConfig;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class SummaryConfigTest extends TestCase
{
    use RefreshDatabase;

    public function testCanCreateSummaryConfig(): void
    {
        $user = User::factory()->create();

        $config = SummaryConfig::create([
            'user_id'     => $user->id,
            'title'       => 'Business Summary',
            'prompt_type' => SummaryConfig::PROMPT_TYPE_BUSINESS,
            'content'     => 'Summarize with a focus on business impact.',
            'ai_model'    => 'claude-sonnet-4-6',
        ]);

        $this->assertDatabaseHas('summary_configs', [
            'user_id'     => $user->id,
            'prompt_type' => SummaryConfig::PROMPT_TYPE_BUSINESS,
        ]);

        $this->assertNotNull($config->id);
    }

    public function testSummaryConfigCanBeLinkedToSources(): void
    {
        $user = User::factory()->create();
        $source1 = Source::factory()->create();
        $source2 = Source::factory()->create();

        $config = SummaryConfig::create([
            'user_id'     => $user->id,
            'title'       => 'My Config',
            'prompt_type' => SummaryConfig::PROMPT_TYPE_DEFAULT,
        ]);

        $config->sources()->attach([$source1->id, $source2->id]);

        $this->assertDatabaseHas('summary_config_sources', ['config_id' => $config->id, 'source_id' => $source1->id]);
        $this->assertDatabaseHas('summary_config_sources', ['config_id' => $config->id, 'source_id' => $source2->id]);
        $this->assertCount(2, $config->sources);
    }

    public function testUserSummaryConfigsRelationship(): void
    {
        $user = User::factory()->create();
        SummaryConfig::factory()->create(['user_id' => $user->id]);
        SummaryConfig::factory()->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->summaryConfigs);
    }
}
