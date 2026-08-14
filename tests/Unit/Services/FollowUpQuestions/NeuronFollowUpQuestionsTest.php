<?php

declare(strict_types=1);

namespace Tests\Unit\Services\FollowUpQuestions;

use Tests\TestCase;
use Tests\Support\FakeAIProvider;
use App\Services\FollowUpQuestions\NeuronFollowUpQuestions;
use App\Services\FollowUpQuestions\FollowUpQuestionsTemplate;

/**
 * @internal
 * @coversNothing
 */
class NeuronFollowUpQuestionsTest extends TestCase
{
    public function testGenerateReturnsTheThreeParsedQuestions(): void
    {
        $provider = new FakeAIProvider("### 1. 均線怎麼用？\n### 2. 族群怎麼選？\n### 3. 停損怎麼設？");

        $questions = (new NeuronFollowUpQuestions($provider))->generate('前一輪的回答內容');

        $this->assertSame(['均線怎麼用？', '族群怎麼選？', '停損怎麼設？'], $questions);
    }

    /**
     * 兩個後端要送出逐字相同的提示詞，產出才有比較意義。指示不拆進 system，
     * 而是連同回答一起當成單一使用者訊息 —— 與 videotranscriber 只收一個
     * `text` 欄位的形狀對齊。
     */
    public function testGenerateSendsTheSamePromptAsTheVideoTranscriberVersion(): void
    {
        $provider = new FakeAIProvider('### 1. a');

        (new NeuronFollowUpQuestions($provider))->generate('前一輪的回答內容');

        $sent = array_map(
            fn ($message): string => $message->getContent(),
            $provider->received
        );

        $this->assertSame(
            [(new FollowUpQuestionsTemplate())->build('前一輪的回答內容')],
            $sent,
            '送出的內容必須與模板組出的完全一致，且只有一則訊息'
        );
    }

    public function testGenerateReturnsEmptyArrayWhenTheModelIgnoresTheFormat(): void
    {
        $provider = new FakeAIProvider('抱歉，我無法產生問題。');

        $this->assertSame([], (new NeuronFollowUpQuestions($provider))->generate('回答'));
    }
}
