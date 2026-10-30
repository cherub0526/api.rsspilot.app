<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\TestCase;
use App\Models\Summary;

/**
 * 摘要當成 AI 素材時的文字（心智圖、頻道 Skill 共用同一套欄位選擇）。
 *
 * @internal
 * @coversNothing
 */
class SummaryAiInputTest extends TestCase
{
    public function testJoinsContentKeyPointsAndKeywords(): void
    {
        $summary = new Summary(['text' => [
            'short_summary' => 'SHORT',
            'long_summary'  => ['content' => 'Body.', 'key_points' => ['One', 'Two'], 'keywords' => ['a', 'b']],
        ]]);

        $this->assertSame("Body.\n\nKey points:\n- One\n- Two\n\nKeywords: a, b", $summary->aiInput());
    }

    public function testShortSummaryIsLeftOut(): void
    {
        $summary = new Summary(['text' => ['short_summary' => 'SHORT', 'long_summary' => ['content' => 'Body.']]]);

        $this->assertSame('Body.', $summary->aiInput());
    }

    public function testMissingOrMalformedTextYieldsAnEmptyString(): void
    {
        $this->assertSame('', (new Summary(['text' => null]))->aiInput());
        $this->assertSame('', (new Summary(['text' => ['long_summary' => 'not an array']]))->aiInput());
        $this->assertSame('', (new Summary(['text' => ['long_summary' => ['content' => '  ', 'key_points' => []]]]))->aiInput());
    }
}
