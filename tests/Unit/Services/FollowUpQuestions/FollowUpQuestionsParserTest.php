<?php

declare(strict_types=1);

namespace Tests\Unit\Services\FollowUpQuestions;

use Tests\TestCase;
use App\Services\FollowUpQuestions\FollowUpQuestionsParser;

/**
 * @internal
 * @coversNothing
 */
class FollowUpQuestionsParserTest extends TestCase
{
    private FollowUpQuestionsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new FollowUpQuestionsParser();
    }

    public function testParseExtractsTheThreeQuestions(): void
    {
        $this->assertSame(
            ['第一個問題？', '第二個問題？', '第三個問題？'],
            $this->parser->parse("### 1. 第一個問題？\n### 2. 第二個問題？\n### 3. 第三個問題？")
        );
    }

    /**
     * 模型常在前後多寫幾句、或改用不同層級的標題，逐行比對才不會整串解析失敗。
     */
    public function testParseIgnoresSurroundingProseAndVaryingHeadingLevels(): void
    {
        $response = <<<'TXT'
            以下是三個延伸問題：

            ## 1. 問題一
            ### 2.   問題二
            #### 3. 問題三

            希望對你有幫助。
            TXT;

        $this->assertSame(['問題一', '問題二', '問題三'], $this->parser->parse($response));
    }

    public function testParseReturnsWhatItFoundWhenTheModelGivesFewer(): void
    {
        $this->assertSame(['只有一題'], $this->parser->parse('### 1. 只有一題'));
    }

    public function testParseReturnsEmptyArrayWhenNothingMatches(): void
    {
        $this->assertSame([], $this->parser->parse('模型完全沒有照格式回answer'));
        $this->assertSame([], $this->parser->parse(''));
    }

    /**
     * 兩套提示詞共用這個解析器，格式相同 —— 用另一套的實際輸出再驗一次，
     * 確保抽出來之後兩邊都還吃得下。
     */
    public function testParseHandlesTheOutputOfTheQuestionAndAnswerPrompt(): void
    {
        $this->assertSame(
            ['均線怎麼用？', '族群怎麼選？', '停損怎麼設？'],
            $this->parser->parse("### 1. 均線怎麼用？\n### 2. 族群怎麼選？\n### 3. 停損怎麼設？")
        );
    }
}
