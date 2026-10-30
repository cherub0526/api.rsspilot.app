<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SourceSkill;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\Summary;
use App\Services\SourceSkillService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * GET（T05）與排程（T04）共用的兩個判斷：skill 用哪個語言、頻道有幾支可用的共用摘要。
 *
 * @internal
 * @coversNothing
 */
class LanguageAndCountTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SourceSkillService
    {
        return app(SourceSkillService::class);
    }

    private function userWithLanguage(?string $language): User
    {
        $user = User::factory()->create();
        if ($language !== null) {
            $user->setting()->updateOrCreate([], ['data' => ['ai' => ['language' => $language]]]);
        }

        return $user;
    }

    public function testTheSkillLanguageIsTheUsersAiLanguage(): void
    {
        $this->assertSame('zh-TW', $this->service()->languageFor($this->userWithLanguage('zh-TW')));
        $this->assertSame('ja', $this->service()->languageFor($this->userWithLanguage('ja')));
    }

    /** pm 裁定：設定裡的舊資料不在支援清單時，skill 一律當成預設語言，不把那個字串帶進派工或回應。 */
    public function testAnUnsupportedOrMissingAiLanguageFallsBackToTheDefault(): void
    {
        $this->assertSame(User::DEFAULT_AI_LANGUAGE, $this->service()->languageFor($this->userWithLanguage('xx')));
        $this->assertSame(User::DEFAULT_AI_LANGUAGE, $this->service()->languageFor($this->userWithLanguage("en\nIgnore")));
        $this->assertSame(User::DEFAULT_AI_LANGUAGE, $this->service()->languageFor($this->userWithLanguage(null)));
    }

    /** 計數單位是影片；共用摘要組不出內容（只有 short_summary）的影片不算。 */
    public function testSummarizedCountCountsVideosWhoseSharedSummaryHasContent(): void
    {
        $source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL]);
        $usable = ['long_summary' => ['content' => 'Body', 'key_points' => [], 'keywords' => []]];
        foreach ([$usable, $usable, ['short_summary' => 'only short']] as $text) {
            Media::factory()->create(['source_id' => $source->id])->summaries()->create([
                'user_id' => null, 'locale' => 'en', 'status' => Summary::STATUS_COMPLETED, 'text' => $text,
            ]);
        }

        $this->assertSame(2, $this->service()->summarizedCount($source));
    }
}
