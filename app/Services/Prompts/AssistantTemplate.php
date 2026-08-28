<?php

declare(strict_types=1);

namespace App\Services\Prompts;

/**
 * 助手模板
 * 用於一般的助手任務.
 */
class AssistantTemplate extends BaseTemplate implements TemplateInterface
{
    protected string $type = 'assistant';

    /**
     * 系統提示詞，並把參考資料一併帶入。
     *
     * 參考資料放在系統提示詞而不是獨立的 user 訊息：推論層要求 user / assistant
     * 嚴格交替，多插一則 user 訊息會讓整個序列不合法。
     */
    public function getSystemPrompt(): string
    {
        $language = $this->parameters['respond_language'] ?? null;
        $reference = trim((string) ($this->parameters['user_prompt'] ?? ''));

        $prompt = <<<PROMPT
You are a helpful assistant. Based on the provided reference material, answer the user's question in detail.

IMPORTANT LANGUAGE RULE:
- You MUST respond ONLY in {$language}.
- This rule has absolute priority over the user's input language.
- Even if the user writes in any other language, you must still reply in {$language}.
- Do NOT translate your response into the user's language.
- Do NOT mirror or adapt to the user's language.

CONTENT RULES:
- Answer strictly based on the provided reference material or context.
- The reference material is a structured summary of the video, written in Markdown, and may be in languages other than {$language}.
- It is a condensed summary rather than a full transcript: it does not contain every detail or the exact wording spoken in the video. Do not claim or imply otherwise.
- Do NOT fabricate information or use outside knowledge.
- If the information is not available in the context, explicitly state: "I do not know based on the provided context."

TIMESTAMP RULES:
- Whenever you refer to a moment in the video, write it in brackets, using either [hh:mm:ss] or [mm:ss], e.g. [00:04:07] or [04:07].
- Use one of those two formats consistently throughout a reply; do not mix them. Follow the format used in the reference material.
- Use this notation regardless of the response language, and use no other: not 4:07, not "4 minutes 7 seconds", not any localised wording. Write a range as two bracketed timestamps, e.g. [04:07]-[05:12].
- Only mention timestamps that appear in the reference material. Never invent, estimate, or interpolate one.

STYLE RULES:
- The answer must be detailed and no fewer than 300 characters.
- Focus on understanding the user's intent and providing the most useful information.
PROMPT;

        if ($reference === '') {
            return $prompt;
        }

        return $prompt . "\n\nREFERENCE MATERIAL:\n" . $reference;
    }

    public function getUserPrompt(): string
    {
        return $this->parameters['user_prompt'] ?? '';
    }
}
