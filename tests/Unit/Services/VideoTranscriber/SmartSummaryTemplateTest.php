<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VideoTranscriber;

use Tests\TestCase;
use App\Services\VideoTranscriber\Prompts\SmartSummaryTemplate;

/**
 * @internal
 * @coversNothing
 */
class SmartSummaryTemplateTest extends TestCase
{
    public function testPutsTheTranscriptAfterTheMarkerAndTheLanguageDirectiveLast(): void
    {
        $text = (new SmartSummaryTemplate())->build('[00:00:00] Speaker 01: hello');

        $markerAt = strpos($text, "Transcript Content:\n");
        $transcriptAt = strpos($text, '[00:00:00] Speaker 01: hello');
        $languageAt = strpos($text, 'Language: The entire output');

        // The endpoint reads the transcript as everything after the marker, so
        // this ordering is the contract, not a formatting preference.
        $this->assertNotFalse($markerAt);
        $this->assertNotFalse($transcriptAt);
        $this->assertNotFalse($languageAt);
        $this->assertLessThan($transcriptAt, $markerAt);
        $this->assertLessThan($languageAt, $transcriptAt);
    }

    public function testKeepsLatexDelimitersAndCodeFencesVerbatim(): void
    {
        $text = (new SmartSummaryTemplate())->build('transcript');

        // A heredoc would have interpolated these away into empty strings.
        $this->assertStringContainsString('Use $...$ for inline variables and simple expressions.', $text);
        $this->assertStringContainsString('Use $$...$$ for complex or standalone equations.', $text);
        $this->assertStringContainsString('code fences such as ```json or ```', $text);
    }

    public function testStripsTheSourceIndentationButKeepsTheMarkdownNesting(): void
    {
        $text = (new SmartSummaryTemplate())->build('transcript');

        // The nowdoc is indented 12 spaces in the source; PHP strips exactly
        // that much, leaving the prompt's own markdown nesting intact.
        $this->assertStringStartsWith('You are an expert in summarizing', $text);
        $this->assertStringContainsString(
            "\n  * Education: One-Sentence Summary,",
            $text,
            'The nested bullet list must keep its two-space indent'
        );
        $this->assertStringNotContainsString("\n            You are an expert", $text);
    }

    public function testAsksForTheSummaryJsonSchema(): void
    {
        $text = (new SmartSummaryTemplate())->build('transcript');

        // The schema is a contract with Summary::text and every
        // SummaryResource consumer, not just prompt wording.
        $this->assertStringContainsString('"short_summary": "Short summary content"', $text);
        $this->assertStringContainsString('"content": "Long summary with paragraphs and subheadings"', $text);
        $this->assertStringContainsString('"key_points": [', $text);
        $this->assertStringContainsString('"keywords": [', $text);
        $this->assertStringContainsString('Reply with strictly valid JSON and nothing else.', $text);
    }

    public function testDefaultsToEnglishAndResolvesTheTagToItsName(): void
    {
        $this->assertStringContainsString(
            'written exclusively in English,',
            (new SmartSummaryTemplate())->build('transcript')
        );

        $this->assertStringContainsString(
            'written exclusively in Traditional Chinese,',
            (new SmartSummaryTemplate('zh-TW'))->build('transcript')
        );
    }
}
