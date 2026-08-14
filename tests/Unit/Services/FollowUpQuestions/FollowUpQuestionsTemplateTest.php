<?php

declare(strict_types=1);

namespace Tests\Unit\Services\FollowUpQuestions;

use Tests\TestCase;
use App\Services\FollowUpQuestions\FollowUpQuestionsTemplate;

/**
 * @internal
 * @coversNothing
 */
class FollowUpQuestionsTemplateTest extends TestCase
{
    private FollowUpQuestionsTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = new FollowUpQuestionsTemplate();
    }

    public function testBuildPutsTheAnswersUnderTheAnswersHeading(): void
    {
        $text = $this->template->build('這是前一輪的回答。');

        $this->assertStringEndsWith("**Answers:**\n這是前一輪的回答。\n", $text);
    }

    /**
     * 指示的字面內容就是契約的一部分 —— 輸出格式與語言規則若被改掉，
     * parse() 與前端顯示都會跟著壞，所以逐條釘住。
     */
    public function testBuildKeepsTheInstructionContract(): void
    {
        $text = $this->template->build('answer');

        foreach ([
            'Based on the below Answers, please generate three related follow-up questions.',
            '1. Be directly related to the original answer and deepen the discussion',
            '2. Spark the user\'s curiosity and encourage further thinking',
            '3. Cover different aspects to broaden the topic',
            '4. The output language must be consistent with the language used in the **Answers** field',
            "### 1. [Question 1]\n### 2. [Question 2]\n### 3. [Question 3]",
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        // Nowdoc 的縮排必須被剝掉，否則整段指示會帶著前導空白送出
        $this->assertStringStartsWith('Based on the below', $text);
    }

    public function testParseExtractsTheThreeQuestions(): void
    {
        $this->assertSame(
            ['第一個問題？', '第二個問題？', '第三個問題？'],
            $this->template->parse("### 1. 第一個問題？\n### 2. 第二個問題？\n### 3. 第三個問題？")
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

        $this->assertSame(['問題一', '問題二', '問題三'], $this->template->parse($response));
    }

    public function testParseReturnsWhatItFoundWhenTheModelGivesFewer(): void
    {
        $this->assertSame(['只有一題'], $this->template->parse('### 1. 只有一題'));
    }

    public function testParseReturnsEmptyArrayWhenNothingMatches(): void
    {
        $this->assertSame([], $this->template->parse('模型完全沒有照格式回answer'));
        $this->assertSame([], $this->template->parse(''));
    }
}
