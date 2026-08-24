<?php

declare(strict_types=1);

namespace App\Services\FollowUpQuestions;

/**
 * 依前一輪的回答產生 3 個延伸問題的提示詞。
 *
 * 兩個後端（videotranscriber、NeuronAI）共用這個模板，送出的提示詞逐字相同，
 * 才有辦法比較兩者的產出差異。
 *
 * 輸出格式的解析在 FollowUpQuestionsParser —— 它同時服務這裡與
 * `App\Services\Prompts\FollowUpQuestionsTemplate`（同名但吃 question + answer
 * 的另一個模板），兩者的 `### N.` 格式相同，解析只該有一份。
 */
class FollowUpQuestionsTemplate
{
    /**
     * 組出完整的提示詞。
     *
     * 沿用 videotranscriber 既有模板的形狀（見 SmartSummaryTemplate、
     * TranslationTemplate）：指示在前、內容在後，整包當成一個 `text` 送出。
     */
    public function build(string $answers, string $language): string
    {
        return $this->instructions($language) . "\n" . $answers . "\n";
    }

    /**
     * 內容以外的所有指示。
     *
     * 維持 Nowdoc 並用 str_replace 代入語言，而不是改成 Heredoc：內文含 `###`
     * 與 `**`，日後若有人在提示詞裡加進 `$`，Heredoc 會靜默地把它當變數插值。
     */
    protected function instructions(string $language): string
    {
        $instructions = <<<'PROMPT'
            Based on the below Answers, please generate three related follow-up questions. These questions should:
            1. Be directly related to the original answer and deepen the discussion
            2. Spark the user's curiosity and encourage further thinking
            3. Cover different aspects to broaden the topic
            4. Write the questions ONLY in :language. This rule has absolute priority
               over the language used in the **Answers** field — do not mirror it.

            Please provide these questions in the following format:
            ### 1. [Question 1]
            ### 2. [Question 2]
            ### 3. [Question 3]

            Ensure that these questions naturally continue the conversation and encourage users to explore the topic in more depth.

            **Answers:**
            PROMPT;

        return str_replace(':language', $language, $instructions);
    }
}
