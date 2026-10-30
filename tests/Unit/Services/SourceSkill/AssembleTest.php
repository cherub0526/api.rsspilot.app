<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SourceSkill;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Source;
use App\Services\SourceSkillService;
use App\Services\SourceSkill\Sanitizer;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * qa 的 SourceSkillAssemblyTest 之外，組裝時的細節規則。
 *
 * @internal
 * @coversNothing
 */
class AssembleTest extends TestCase
{
    use RefreshDatabase;

    private function assemble(string $llm, array $mediaOverrides = [], string $language = 'en'): array
    {
        $source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL, 'title' => 'Example Channel']);
        $media = [Media::factory()->create(array_merge([
            'source_id'    => $source->id,
            'title'        => 'Video',
            'resource_id'  => 'abcdefghijk',
            'published_at' => '2026-10-01 08:00:00',
        ], $mediaOverrides))];

        return app(SourceSkillService::class)->assemble($source, $language, $llm, $media);
    }

    public function testTopicsLineFillsTheDescriptionAndIsRemovedFromTheBody(): void
    {
        $skill = $this->assemble("TOPICS: hooks, <b>retention</b>\n\n## Principles\nText.");

        $this->assertStringContainsString('hooks, retention', $skill['description']);
        $this->assertStringNotContainsString('TOPICS', $skill['content']);
        $this->assertStringNotContainsString('<b>', $skill['description']);
    }

    public function testDescriptionFallsBackToOutlineWithoutTopics(): void
    {
        $skill = $this->assemble("## Principles\nText.\n\n## Checklist\nMore.");

        $this->assertStringContainsString('Principles, Checklist', $skill['description']);
    }

    public function testChineseDescriptionUsesTheChineseTemplate(): void
    {
        $skill = $this->assemble("## 原則\nText.", [], 'zh-TW');

        $this->assertStringContainsString('Example Channel', $skill['description']);
        $this->assertStringContainsString('原則', $skill['description']);
        $this->assertStringContainsString('## 用 RSSPilot MCP 查細節', $skill['content']);
    }

    public function testResourceIdPrefixIsStrippedFromTheLink(): void
    {
        $skill = $this->assemble("## A\nB", ['resource_id' => 'yt:video:abcdefghijk']);

        $this->assertStringContainsString('https://www.youtube.com/watch?v=abcdefghijk', $skill['references']);
        $this->assertStringNotContainsString('yt:video', $skill['references']);
    }

    public function testMediaWithAnInvalidIdIsLeftOut(): void
    {
        $skill = $this->assemble("## A\nB", ['resource_id' => 'bad id/../x', 'title' => 'Broken']);

        $this->assertStringNotContainsString('Broken', $skill['references']);
    }

    public function testTriggeredRulesAreReportedWithoutText(): void
    {
        $skill = $this->assemble("## A\nRun !`id` now.", ['title' => 'See https://evil.example/x']);

        $this->assertSame([Sanitizer::RULE_BANG_COMMAND, Sanitizer::RULE_FOREIGN_URL], $skill['sanitized_rules']);
    }

    // ── security T12 r1 ────────────────────────────────────

    /** F6：TOPICS 不能把觸發範圍寫成「所有任務」。 */
    public function testBroadTopicsAreDropped(): void
    {
        $skill = $this->assemble("TOPICS: every task, anything the user asks, 任何問題, investing, a b c d e f\n\n## A\nB");

        $this->assertStringContainsString('investing', $skill['description']);
        foreach (['every task', 'anything', '任何問題', 'a b c d e f'] as $broad) {
            $this->assertStringNotContainsString($broad, $skill['description']);
        }
    }

    /**
     * F7：模型留下沒關的 fence（含 4 個反引號），即使沒有截斷，MCP 段落也不能被包進去。
     */
    public function testUnclosedFenceIsClosedBeforeTheSystemSection(): void
    {
        $skill = $this->assemble("## Example\n````python\nprint('x')\n```\nstill code");

        $inside = null;
        foreach (explode("\n", $skill['content']) as $line) {
            if ($inside === null && preg_match('/^\s*(`{3,}|~{3,})/', $line, $m)) {
                $inside = $m[1];
            } elseif ($inside !== null && preg_match('/^\s*' . preg_quote($inside[0], '/') . '{' . strlen($inside) . ',}\s*$/', $line)) {
                $inside = null;
            } elseif ($inside !== null && str_contains($line, '<YOUR_RSSPILOT_API_KEY>')) {
                $this->fail('MCP section is inside a code block');
            }
        }
        $this->assertNull($inside, 'every fence is closed');
    }

    /** GET 從存下來的 content 重算目錄：系統插入的 MCP 段落標題不算模型的目錄（每種語言都一樣）。 */
    public function testTheOutlineOfStoredContentLeavesOutTheSystemSection(): void
    {
        foreach (['en', 'zh-TW', 'zh-CN'] as $language) {
            $skill = $this->assemble("## Principles\nA\n\n## Checklist\nB", ['resource_id' => "vid-{$language}-001"], $language);

            $this->assertSame(['Principles', 'Checklist'], app(SourceSkillService::class)->outlineOf($skill['content']), $language);
        }
    }
}
