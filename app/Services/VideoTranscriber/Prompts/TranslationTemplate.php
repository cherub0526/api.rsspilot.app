<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber\Prompts;

use App\Utils\Const\ISO6391;

/**
 * A translation prompt for the same `summary/completions` endpoint the smart
 * summary uses — the endpoint is a general completion, the prompt is what
 * makes it a translation.
 *
 * The content is passed through untouched and comes back in the same shape, so
 * a stored `Summary::text` can be translated and written straight back without
 * a second parser. That shape-preservation is the whole contract: the rules
 * below spell out JSON, Markdown and plain text separately because a model
 * left to its own devices will happily "improve" the structure, translate JSON
 * keys, or wrap the answer in prose.
 *
 * @see SmartSummaryTemplate for the layout this mirrors
 */
class TranslationTemplate
{
    /**
     * Translating into the source language would be a no-op, so unlike the
     * summary this defaults to Traditional Chinese rather than English.
     */
    public const DEFAULT_LANGUAGE_CODE = 'zh-TW';

    public function __construct(
        protected string $languageCode = self::DEFAULT_LANGUAGE_CODE,
    ) {
    }

    /**
     * Assemble the full `text` payload for the given content.
     */
    public function build(string $content): string
    {
        return $this->instructions()
            . "\n" . $content
            . "\n\n" . $this->languageDirective();
    }

    /**
     * Everything above the content. Nowdoc so ``` fences and any `$...$` LaTeX
     * inside the rules reach the API verbatim.
     */
    protected function instructions(): string
    {
        return <<<'PROMPT'
            You are a professional translator. Translate the content below into the target language stated at the end of this message.

            **Output Format (CRITICAL):**
            Reply with the translation and nothing else. Do not add commentary, notes, or explanations, and do not wrap the reply in code fences such as ```json or ```.
            The translation must keep exactly the same shape as the input:

            - If the input is JSON, reply with JSON of the identical structure. Translate only the string values. Never translate, rename, reorder, add, or remove keys, and keep arrays the same length.
            - If the input is Markdown, keep every heading level, table, list, blockquote, emphasis marker, and line break where it is.
            - If the input is plain text, reply with plain text and keep its paragraph breaks.

            **Translation Rules:**
            - Preserve the meaning, tone, and register of the original. Do not summarise, expand, reorder, or omit anything.
            - Keep timestamps, numbers, units, URLs, code, and file paths exactly as they appear.
            - Leave product names, company names, and established technical terms in their original form when that is how they are normally written in the target language.
            - Text already written in the target language stays as it is.
            - Every mathematical variable, symbol, or formula keeps its LaTeX delimiters: $...$ inline, $$...$$ standalone.

            Content:
            PROMPT;
    }

    protected function languageDirective(): string
    {
        return 'Language: The entire output must be written exclusively in '
            . $this->languageName() . ', '
            . 'including all headings, labels, tables, bullet points, annotations, and explanatory '
            . 'text, with no mixed-language content unless the original deliberately mixes languages.';
    }

    /**
     * Resolve the code into the language name the prompt states.
     *
     * `ISO6391::getNameByCode()` is an `array_search`, so an unregistered code
     * yields `false`. The code stands in for the name in that case rather than
     * leaving the directive naming no language at all, which would let the
     * model answer in whatever it liked.
     */
    protected function languageName(): string
    {
        $name = ISO6391::getNameByCode($this->languageCode);

        return is_string($name) ? $name : $this->languageCode;
    }
}
