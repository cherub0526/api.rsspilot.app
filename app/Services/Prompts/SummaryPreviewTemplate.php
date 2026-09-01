<?php

declare(strict_types=1);

namespace App\Services\Prompts;

/**
 * 「測試 AI 摘要」用的模板。
 *
 * 與 CustomPromptTemplate 的差別在輸出結構：那一支產的是要存起來的
 * short_summary / long_summary，這一支產的是給人當場看的分段落預覽。
 *
 * 使用者的 prompt 決定語氣、觀點與要談什麼；段落結構是我們加的外框，
 * 因為預覽畫面要照 heading + 條列去排。兩者衝突時以 JSON 結構優先，
 * 否則回應解析不了，整個預覽就只能顯示錯誤。
 */
class SummaryPreviewTemplate extends BaseTemplate
{
    protected string $type = 'summary_preview';

    public function getSystemPrompt(): string
    {
        $language = $this->parameters['language'] ?? 'Traditional Chinese';
        $customSystemPrompt = $this->parameters['system_prompt'] ?? '';

        return <<<PROMPT
{$customSystemPrompt}

CRITICAL OUTPUT RULE (HIGHEST PRIORITY):
- The output MUST strictly follow the required JSON structure below.
- Do NOT add any text before or after the JSON.
- Do NOT modify the JSON schema.
- The response MUST be valid JSON.

If any instruction conflicts with the JSON structure requirement,
you MUST preserve the JSON structure above all else.

CONTENT PRIORITY:
- Follow the custom instructions above for tone, style, and perspective.
- The number of sections and their headings are up to you: use whatever
  the custom instructions ask for. Aim for 2 to 6 sections.

STRICT CONTENT RULES:
- Use only the provided text.
- Do not add external information or assumptions.
- Output language must be {$language}.
- Each item must be a complete, self-contained sentence.

OUTPUT FORMAT (STRICTLY FOLLOW):

{
  "sections": [
    {
      "heading": "Section heading",
      "items": [
        "Point 1",
        "Point 2"
      ]
    }
  ]
}
PROMPT;
    }

    public function getUserPrompt(): string
    {
        return $this->parameters['user_prompt'] ?? 'Please summarize the following content:';
    }
}
