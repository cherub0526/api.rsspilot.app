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
        $text = $this->template->build('這是前一輪的回答。', 'English');

        $this->assertStringEndsWith("**Answers:**\n這是前一輪的回答。\n", $text);
    }

    /**
     * 指示的字面內容就是契約的一部分 —— 輸出格式與語言規則若被改掉，
     * parse() 與前端顯示都會跟著壞，所以逐條釘住。
     */
    public function testBuildKeepsTheInstructionContract(): void
    {
        $text = $this->template->build('answer', 'Traditional Chinese');

        foreach ([
            'Based on the below Answers, please generate three related follow-up questions.',
            '1. Be directly related to the original answer and deepen the discussion',
            '2. Spark the user\'s curiosity and encourage further thinking',
            '3. Cover different aspects to broaden the topic',
            '4. Write the questions ONLY in Traditional Chinese.',
            "### 1. [Question 1]\n### 2. [Question 2]\n### 3. [Question 3]",
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        // Nowdoc 的縮排必須被剝掉，否則整段指示會帶著前導空白送出
        $this->assertStringStartsWith('Based on the below', $text);

        // 佔位符必須被換掉——漏換的話送出的是字面的 ":language"
        $this->assertStringNotContainsString(':language', $text);
    }

    /**
     * 語言直接決定產出，所以每個語言名稱都要真的進到指示裡，
     * 不能只驗其中一個。
     */
    public function testBuildInjectsTheGivenLanguage(): void
    {
        foreach (['English', 'Traditional Chinese', 'Japanese'] as $language) {
            $this->assertStringContainsString(
                "Write the questions ONLY in {$language}.",
                $this->template->build('answer', $language)
            );
        }
    }
}
