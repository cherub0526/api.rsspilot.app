<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber\Prompts;

/**
 * The prompt videotranscriber.ai's `summary/completions` endpoint expects as
 * its flat `text` field.
 *
 * Deliberately not a `App\Services\Prompts\BaseTemplate`: those model OpenAI's
 * system/user message split, whereas this endpoint takes one concatenated
 * string. Implementing `buildMessages()` here would produce a shape nothing
 * ever reads.
 *
 * The layout is load-bearing and mirrors what the service's own web client
 * sends: instructions first, the transcript after the `Transcript Content:`
 * marker, and the language directive last. Reordering these changes the
 * output, so keep the assembly in `build()` as it is.
 *
 * The prompt asks for JSON in the same shape the OpenAI-backed
 * `App\Services\Prompts\SummaryTemplate` produces, so both kinds of summary
 * land in `Summary::text` under one contract. Changing the schema here means
 * changing what every `SummaryResource` consumer reads.
 */
class SmartSummaryTemplate
{
    public const DEFAULT_LANGUAGE = 'English';

    public function __construct(
        protected string $language = self::DEFAULT_LANGUAGE,
    ) {
    }

    /**
     * Assemble the full `text` payload for the given transcript.
     */
    public function build(string $transcript): string
    {
        return $this->instructions()
            . "\n" . $transcript
            . "\n\n" . $this->languageDirective();
    }

    /**
     * Everything above the transcript. Nowdoc, not heredoc: the prompt is full
     * of `$...$` LaTeX delimiters and ``` fences that must reach the API
     * verbatim rather than being interpolated.
     */
    protected function instructions(): string
    {
        return <<<'PROMPT'
            You are an expert in summarizing transcript content, skilled at extracting key information and generating high-quality, well-structured summaries.
            Based on the provided transcript, complete the following task.

            **Task Description:**

            Before writing the final output, internally complete these steps. Do not show these internal steps, the identified video type, or the module-planning result in the output:

            * Identify the exact video type, such as academic lecture, meeting, product launch, tutorial, interview, vlog, seminar, etc.
            * Based on the identified type, create 3–4 relevant module titles, such as:

              * Education: One-Sentence Summary, Chapter Highlights, Core Learning Points, Key Questions
              * Meeting: Meeting Summary, Key Decisions, Action Items, Next Steps
              * Tutorial: Overview, Step-by-Step Guide, Key Tips, Common Issues

            Use the identified video type and planned module titles only to guide the structure of `long_summary.content`. Do not explicitly state the video type, category, workflow, reasoning process, or module-planning result unless this information is directly supported by the transcript and naturally belongs in the summary.

            **Summary Settings:**
            Depth: Provide balanced detail with key context and useful takeaways.
            Length: Write a moderate-length summary with enough detail to be useful, with at least 800 characters.
            Timestamp: Include timestamps for important points when available.

            Only include content supported by the transcript; do not fabricate details. Mark uncertain or missing information as *Not specified/Uncertain*.
            Timestamps format: Use one timestamp format consistently throughout the output: either [hh:mm:ss] or [mm:ss]. Do not mix both formats. Follow the timestamp style used in the provided Transcript Content as the reference
            Please organize the summary sections strictly according to the chronological order and logical flow of the Transcript Content, ensuring a coherent structure while avoiding topic jumps, disordered sections, repetitive summarization, or reversed cause-and-effect relationships.

            **Output Format (CRITICAL):**
            Reply with strictly valid JSON and nothing else. Do not wrap it in code fences such as ```json or ```, and do not write any text before or after the JSON.

            {
              "short_summary": "Short summary content",
              "long_summary": {
                "content": "Long summary with paragraphs and subheadings",
                "key_points": [
                  "Key point 1",
                  "Key point 2",
                  "..."
                ],
                "keywords": [
                  "Keyword 1",
                  "Keyword 2",
                  "..."
                ]
              }
            }

            Field rules:
            - `short_summary`: one highly condensed paragraph covering the core points, as plain text with no Markdown headings.
            - `long_summary.content`: the full summary as Markdown, beginning with a concise, content-specific level-1 heading (`# Title`) that names the central topic or main takeaway. Use subheadings, and use tables for timelines, comparisons, definitions, decisions, or action items when helpful. Bold key insights, terms, and conclusions. Do not include separators such as ---.
            - `long_summary.key_points`: the main takeaways, one per array entry.
            - `long_summary.keywords`: the significant terms, one per array entry.

            ## Mathematical Formatting (CRITICAL)
            - Every mathematical variable, symbol, or formula must be wrapped in LaTeX delimiters.
            - Use $...$ for inline variables and simple expressions.
            - Use $$...$$ for complex or standalone equations.
            - Never use plain text, HTML entities, or markdown code blocks for math.

            Transcript Content:
            PROMPT;
    }

    protected function languageDirective(): string
    {
        return "Language: The entire output must be written exclusively in {$this->language}, "
            . 'including all headings, labels, tables, bullet points, annotations, and explanatory '
            . 'text, with no mixed-language content unless explicitly present in the original transcript.';
    }
}
