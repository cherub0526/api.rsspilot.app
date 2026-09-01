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

    /**
     * 命名避開 TestCase 既有的 public json()（那是發 HTTP 請求用的）。
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public function testParsesTheSameShapeAsStoredSummaries(): void
    {
        $summary = $this->service()->parseSummary($this->encode([
            'short_summary' => '一句話總結。',
            'long_summary'  => [
                'content'    => '完整的長摘要。',
                'key_points' => ['重點一', '重點二'],
                'keywords'   => ['AI', '工作流程'],
            ],
        ]));

        $this->assertSame('一句話總結。', $summary['short_summary']);
        $this->assertSame('完整的長摘要。', $summary['long_summary']['content']);
        $this->assertSame(['重點一', '重點二'], $summary['long_summary']['key_points']);
        $this->assertSame(['AI', '工作流程'], $summary['long_summary']['keywords']);
    }

    public function testMissingListsBecomeEmptyArrays(): void
    {
        // key_points 與 keywords 是附加資訊，缺了不影響這次試跑能不能看。
        $summary = $this->service()->parseSummary($this->encode([
            'short_summary' => '一句話總結。',
            'long_summary'  => ['content' => '長摘要。'],
        ]));

        $this->assertSame([], $summary['long_summary']['key_points']);
        $this->assertSame([], $summary['long_summary']['keywords']);
    }

    public function testAcceptsAResponseWithOnlyALongSummary(): void
    {
        $summary = $this->service()->parseSummary($this->encode([
            'long_summary' => ['content' => '只有長摘要。'],
        ]));

        $this->assertSame('', $summary['short_summary']);
        $this->assertSame('只有長摘要。', $summary['long_summary']['content']);
    }

    public function testTrimsAndDropsBlankListItems(): void
    {
        $summary = $this->service()->parseSummary($this->encode([
            'short_summary' => 'x',
            'long_summary'  => ['content' => 'y', 'keywords' => ['  AI  ', '   ', '']],
        ]));

        $this->assertSame(['AI'], $summary['long_summary']['keywords']);
    }

    public function testRejectsOutputThatIsNotJson(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->service()->parseSummary('這不是 JSON');
    }

    public function testRejectsWhenBothSummariesAreEmpty(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->service()->parseSummary($this->encode([
            'short_summary' => '   ',
            'long_summary'  => ['content' => ''],
        ]));
    }
}
