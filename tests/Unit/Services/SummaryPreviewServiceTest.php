<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\SummaryPreviewService;
use App\Exceptions\InvalidRequestException;

/**
 * 模型回應的解析。這一層不碰網路，所以直接測。
 *
 * @internal
 * @coversNothing
 */
class SummaryPreviewServiceTest extends TestCase
{
    private function service(): SummaryPreviewService
    {
        return new SummaryPreviewService();
    }

    public function testParsesWellFormedSections(): void
    {
        $sections = $this->service()->parseSections(json_encode([
            'sections' => [
                ['heading' => '主要論點', 'items' => ['第一點', '第二點']],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame([['heading' => '主要論點', 'items' => ['第一點', '第二點']]], $sections);
    }

    public function testDropsSectionsWithoutItems(): void
    {
        // 只有標題、底下空白的區塊在畫面上像是壞了。
        $sections = $this->service()->parseSections(json_encode([
            'sections' => [
                ['heading' => '空的', 'items' => []],
                ['heading' => '有內容', 'items' => ['一句話']],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertCount(1, $sections);
        $this->assertSame('有內容', $sections[0]['heading']);
    }

    public function testTrimsAndDropsBlankItems(): void
    {
        $sections = $this->service()->parseSections(json_encode([
            'sections' => [['heading' => 'x', 'items' => ['  有內容  ', '   ', '']]],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame(['有內容'], $sections[0]['items']);
    }

    public function testRejectsOutputThatIsNotJson(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->service()->parseSections('這不是 JSON');
    }

    public function testRejectsJsonWithoutASectionsKey(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->service()->parseSections(json_encode(['short_summary' => 'x']));
    }

    public function testRejectsWhenEverySectionIsEmpty(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->service()->parseSections(json_encode([
            'sections' => [['heading' => '空的', 'items' => []]],
        ], JSON_UNESCAPED_UNICODE));
    }
}
