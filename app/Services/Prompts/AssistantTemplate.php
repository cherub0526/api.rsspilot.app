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

    public function getSystemPrompt(): string
    {
        $language = $this->parameters['respond_language'] ?? null;

        return <<<PROMPT
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
- If the summary happens to carry timestamps, you may cite them exactly as they appear — never invent, estimate, or interpolate one.
- Do NOT fabricate information or use outside knowledge.
- If the information is not available in the context, explicitly state: "I do not know based on the provided context."

STYLE RULES:
- The answer must be detailed and no fewer than 300 characters.
- Focus on understanding the user's intent and providing the most useful information.
PROMPT;
    }

    public function getUserPrompt(): string
    {
        return $this->parameters['user_prompt'] ?? '';
    }
}
