<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VideoTranscriber;

use Tests\TestCase;
use App\Services\VideoTranscriber\Prompts\TranslationTemplate;

/**
 * @internal
 * @coversNothing
 */
class TranslationTemplateTest extends TestCase
{
    public function testPutsTheContentAfterTheMarkerAndTheLanguageDirectiveLast(): void
    {
        $text = (new TranslationTemplate())->build('{"short_summary":"hello"}');

        $markerAt = strpos($text, "Content:\n");
        $contentAt = strpos($text, '{"short_summary":"hello"}');
        $languageAt = strpos($text, 'Language: The entire output');

        $this->assertNotFalse($markerAt);
        $this->assertLessThan($contentAt, $markerAt);
        $this->assertLessThan($languageAt, $contentAt);
    }

    public function testSpellsOutTheShapePreservationRules(): void
    {
        $text = (new TranslationTemplate())->build('content');

        // Shape preservation is the contract that lets a translated summary go
        // straight back into Summary::text, so these rules are load-bearing.
        $this->assertStringContainsString('Translate only the string values.', $text);
        $this->assertStringContainsString('Never translate, rename, reorder, add, or remove keys', $text);
        $this->assertStringContainsString('keep arrays the same length', $text);
        $this->assertStringContainsString('If the input is Markdown', $text);
        $this->assertStringContainsString('If the input is plain text', $text);
    }

    public function testForbidsCommentaryAndCodeFences(): void
    {
        $text = (new TranslationTemplate())->build('content');

        $this->assertStringContainsString('Reply with the translation and nothing else.', $text);
        $this->assertStringContainsString('code fences such as ```json or ```', $text);
    }

    public function testDefaultsToTraditionalChineseAndResolvesTheTagToItsName(): void
    {
        $this->assertStringContainsString(
            'written exclusively in Traditional Chinese,',
            (new TranslationTemplate())->build('content')
        );

        $this->assertStringContainsString(
            'written exclusively in Japanese,',
            (new TranslationTemplate('ja'))->build('content')
        );
    }

    public function testStripsTheSourceIndentation(): void
    {
        $text = (new TranslationTemplate())->build('content');

        $this->assertStringStartsWith('You are a professional translator.', $text);
        $this->assertStringNotContainsString("\n            You are", $text);
    }
}
