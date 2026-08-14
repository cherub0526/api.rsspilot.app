<?php

declare(strict_types=1);

namespace App\Services\FollowUpQuestions;

/**
 * 依前一輪的回答產生 3 個延伸問題的提示詞，以及對回應的解析。
 *
 * 提示詞與解析放在一起是刻意的：`### 1.` 這個輸出格式是由提示詞規定的，
 * 兩者是同一份契約的兩面，分開放很容易改了一邊忘了另一邊。
 *
 * 兩個後端（videotranscriber、NeuronAI）共用這個模板，送出的提示詞逐字相同，
 * 才有辦法比較兩者的產出差異。
 *
 * 注意 `App\Services\Prompts\FollowUpQuestionsTemplate` 是另一個同名類別，
 * 走 BaseTemplate / OpenRouter 那條路且目前無人使用，與這裡無關。
 */
class FollowUpQuestionsTemplate
{
    /**
     * 組出完整的提示詞。
     *
     * 沿用 videotranscriber 既有模板的形狀（見 SmartSummaryTemplate、
     * TranslationTemplate）：指示在前、內容在後，整包當成一個 `text` 送出。
     */
    public function build(string $answers): string
    {
        return $this->instructions() . "\n" . $answers . "\n";
    }

    /**
     * 從回應中取出各個問題。
     *
     * 模型不保證乖乖給滿 3 題，也可能在前後多寫幾句，所以逐行比對格式而不是
     * 整串硬切；解析到幾題就回幾題，由呼叫端決定不足時要怎麼辦。
     *
     * @return string[]
     */
    public function parse(string $response): array
    {
        if (preg_match_all('/^\s*#{1,6}\s*\d+\.\s*(.+?)\s*$/mu', $response, $matches) === 0) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), $matches[1]),
            static fn (string $question): bool => $question !== ''
        ));
    }

    /**
     * 內容以外的所有指示。Nowdoc：內文含 `###` 與 `**`，不能讓 PHP 插值。
     */
    protected function instructions(): string
    {
        return <<<'PROMPT'
            Based on the below Answers, please generate three related follow-up questions. These questions should:
            1. Be directly related to the original answer and deepen the discussion
            2. Spark the user's curiosity and encourage further thinking
            3. Cover different aspects to broaden the topic
            4. The output language must be consistent with the language used in the **Answers** field

            Please provide these questions in the following format:
            ### 1. [Question 1]
            ### 2. [Question 2]
            ### 3. [Question 3]

            Ensure that these questions naturally continue the conversation and encourage users to explore the topic in more depth.

            **Answers:**
            PROMPT;
    }
}
