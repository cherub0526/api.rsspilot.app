<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use App\Models\SourceSkill;
use App\Models\SourceSkillDownload;
use Hyperf\Database\Exception\QueryException;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * channel-skill T02：source_skills 與 source_skill_downloads 的資料規則。
 *
 * @internal
 * @coversNothing
 */
class SourceSkillTest extends TestCase
{
    use RefreshDatabase;

    public function testFactoryCreatesASkillWithUlidAndArrayMediaIds(): void
    {
        $skill = SourceSkill::factory()->create(['media_ids' => ['m1', 'm2']]);

        $fresh = SourceSkill::find($skill->id);

        $this->assertMatchesRegularExpression('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/i', (string) $fresh->id);
        $this->assertSame(['m1', 'm2'], $fresh->media_ids);
    }

    /**
     * 同頻道同語言全站共用一份；唯一索引是併發插隊時的最後防線。
     */
    public function testOneSkillPerSourceAndLanguage(): void
    {
        $source = Source::factory()->create();
        SourceSkill::factory()->create(['source_id' => $source->id, 'language' => 'zh-TW']);
        SourceSkill::factory()->create(['source_id' => $source->id, 'language' => 'en']);

        $this->expectException(QueryException::class);

        SourceSkill::factory()->create(['source_id' => $source->id, 'language' => 'zh-TW']);
    }

    public function testStatusConstants(): void
    {
        $this->assertSame(
            ['generating', 'completed', 'failed'],
            [SourceSkill::STATUS_GENERATING, SourceSkill::STATUS_COMPLETED, SourceSkill::STATUS_FAILED]
        );
    }

    /**
     * 下載紀錄只記發生時間，沒有 updated_at。
     */
    public function testDownloadRecordsOnlyCreatedAt(): void
    {
        $user = User::factory()->create();
        $skill = SourceSkill::factory()->create();

        $download = SourceSkillDownload::create([
            'user_id'         => $user->id,
            'source_skill_id' => $skill->id,
            'kind'            => SourceSkillDownload::KIND_ZIP,
        ]);

        $fresh = SourceSkillDownload::find($download->id);

        $this->assertNotNull($fresh->created_at);
        $this->assertNull(SourceSkillDownload::UPDATED_AT);
        $this->assertSame(['zip', 'install'], [SourceSkillDownload::KIND_ZIP, SourceSkillDownload::KIND_INSTALL]);
    }
}
