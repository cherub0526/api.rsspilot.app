<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use Tests\TestCase;
use App\Utils\ChineseVariant;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @covers \App\Utils\ChineseVariant
 */
class ChineseVariantTest extends TestCase
{
    #[DataProvider('samples')]
    public function testDetect(string $text, ?string $expected): void
    {
        $this->assertSame($expected, ChineseVariant::detect($text));
    }

    /**
     * @return array<string, array{string, null|string}>
     */
    public static function samples(): array
    {
        return [
            'traditional' => [
                '這個學習說明會在下週開始，請大家準時參加',
                ChineseVariant::LOCALE_ZH_TW,
            ],
            'simplified' => [
                '这个学习说明会在下周开始，请大家准时参加',
                ChineseVariant::LOCALE_ZH_CN,
            ],
            // 兩套都通用的字撐不起判斷，回 null 讓呼叫端自己決定預設值。
            'shared characters only' => ['今天很好', null],
            'no chinese at all'      => ['hello world', null],
            'empty'                  => ['', null],
            // 日文漢字與簡體共用字形（学・会・体・点），所以這裡會判成簡體——
            // 呼叫端必須先確定語言是中文才問這個問題。
            'japanese is out of scope' => ['学会体点', ChineseVariant::LOCALE_ZH_CN],
        ];
    }

    public function testWeighsHowOftenEachSetAppearsRatherThanWhetherItAppears(): void
    {
        // 一個簡體字混在整段繁體裡（轉錄模型偶爾會吐出來）不該翻盤。
        $text = '這個學習說明會在下週開始，請大家準時參加，学';

        $this->assertSame(ChineseVariant::LOCALE_ZH_TW, ChineseVariant::detect($text));
    }
}
