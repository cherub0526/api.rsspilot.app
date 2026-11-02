<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use ZipArchive;
use Tests\TestCase;
use App\Models\Source;
use App\Models\SourceSkill;
use App\Services\SourceSkillService;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * channel-skill T13：zip 打包（S02 的內容、SR-9 固定路徑）。
 *
 * 介面約定：`SourceSkillService::zip(SourceSkill $skill): string`
 *   回傳 `sys_get_temp_dir()` 底下唯一檔名的 zip 路徑；呼叫端（T06 下載）送完即刪。
 *   entry 只有 `<name>/SKILL.md` 與 `<name>/references/videos.md`，內容是資料列的 content／references。
 *   `<name>` 由系統決定（skillName()），不採用資料列或任何外部字串當路徑。
 *
 * @internal
 * @coversNothing
 */
class SourceSkillZipTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function zip(SourceSkill $skill): string
    {
        $path = app(SourceSkillService::class)->zip($skill);
        $this->paths[] = $path;

        return $path;
    }

    /** @return array<string, string> entry 名稱 → 內容 */
    private function entries(string $path): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'a readable zip');
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_ends_with($name, '/')) {
                $entries[$name] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();

        return $entries;
    }

    private function skill(array $attributes = []): SourceSkill
    {
        $source = Source::factory()->create(['type' => Source::TYPE_YOUTUBE_CHANNEL, 'title' => 'Knife Club']);

        return SourceSkill::factory()->create(array_merge([
            'source_id'  => $source->id,
            'name'       => app(SourceSkillService::class)->skillName($source),
            'content'    => "---\nname: x\ndescription: y\n---\n\n## Principles\n",
            'references' => "# Videos\n\n- Lesson\n",
        ], $attributes));
    }

    public function testS02ZipHoldsExactlyTheTwoFilesUnderTheSkillName(): void
    {
        $skill = $this->skill();

        $entries = $this->entries($this->zip($skill));

        $this->assertSame([
            "{$skill->name}/SKILL.md"             => $skill->content,
            "{$skill->name}/references/videos.md" => $skill->references,
        ], $entries);
    }

    public function testTheZipIsAUniqueFileInTheTempDirectory(): void
    {
        $skill = $this->skill();

        $first = $this->zip($skill);
        $second = $this->zip($skill);

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith(rtrim(sys_get_temp_dir(), '/') . '/', $first);
        $this->assertFileExists($first);
        $this->assertFileExists($second);
    }

    /** SR-9：資料列的 name 被竄改成路徑，zip 也只會有系統固定的兩個 entry。 */
    public function testSr9ATamperedNameColumnCannotChooseTheEntryPaths(): void
    {
        $skill = $this->skill(['name' => '../../../.ssh/authorized_keys']);
        $expected = app(SourceSkillService::class)->skillName($skill->source);

        $entries = array_keys($this->entries($this->zip($skill)));

        sort($entries);
        $this->assertSame(["{$expected}/SKILL.md", "{$expected}/references/videos.md"], $entries);
        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('#^rsspilot-[a-z0-9-]{1,55}/(SKILL\.md|references/videos\.md)$#', $entry);
        }
    }
}
