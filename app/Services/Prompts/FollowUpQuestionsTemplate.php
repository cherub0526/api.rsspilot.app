<?php

declare(strict_types=1);

namespace App\Services\Prompts;

/**
 * 延伸問題模板
 * 根據上一輪問答，產生 3 個延伸問題.
 */
class FollowUpQuestionsTemplate extends BaseTemplate implements TemplateInterface
{
    protected string $type = 'follow_up_questions';

    public function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Based on the following question and answer, generate exactly 3 related follow-up questions.

Rules:
1. The questions must be related to and deepen the previous topic.
2. Each question should spark the user's curiosity.
3. The questions should cover different aspects of the topic.
4. The output language MUST match the language of the answer.

Output format (strictly follow):
### 1. {question 1}
### 2. {question 2}
### 3. {question 3}
PROMPT;
    }

    public function getUserPrompt(): string
    {
        $question = $this->parameters['user_question'] ?? '';
        $answer = $this->parameters['previous_answer'] ?? '';

        return <<<PROMPT
Question:
{$question}

Answer:
{$answer}
PROMPT;
    }
}
