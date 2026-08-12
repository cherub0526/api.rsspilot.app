<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VideoTranscriber;

use Tests\TestCase;
use App\Services\VideoTranscriber\SummaryStreamParser;

/**
 * @internal
 * @coversNothing
 */
class SummaryStreamParserTest extends TestCase
{
    private function parse(string $stream): string
    {
        return (new SummaryStreamParser())->parse($stream);
    }

    /**
     * Build a stream out of raw `data:` payloads.
     */
    private function stream(string ...$payloads): string
    {
        return implode('', array_map(fn (string $p) => "data: {$p}\n\n", $payloads));
    }

    public function testConcatenatesTheMessageFragmentsInOrder(): void
    {
        // The real opening chunk carries only summary_id, and the fragments
        // split mid-word — "# Reflections" arrives as "#", " Ref", "lections".
        $stream = $this->stream(
            '{"summary_id": "06a7c902cf667ad8800055ccaa380cd4"}',
            '{"message": "#", "conversation_id": ""}',
            '{"message": " Ref", "conversation_id": ""}',
            '{"message": "lections", "conversation_id": ""}',
        );

        $this->assertSame('# Reflections', $this->parse($stream));
    }

    public function testKeepsSignificantWhitespaceInsideAFragment(): void
    {
        // A fragment that is nothing but a space is the word separator —
        // trimming it would run the words together.
        $stream = $this->stream(
            '{"message": "one"}',
            '{"message": " "}',
            '{"message": "two"}',
            '{"message": "\n\n"}',
            '{"message": "three"}',
        );

        $this->assertSame("one two\n\nthree", $this->parse($stream));
    }

    public function testIgnoresChunksThatCarryNoMessage(): void
    {
        $stream = $this->stream(
            '{"summary_id": "abc"}',
            '{"conversation_id": ""}',
            '{"message": null}',
            '{"message": "kept"}',
        );

        $this->assertSame('kept', $this->parse($stream));
    }

    public function testIgnoresHeartbeatsBlankLinesAndNonDataFields(): void
    {
        $stream = implode("\n", [
            ': heartbeat',
            '',
            'event: message',
            'id: 1',
            'data: {"message": "only this"}',
            '',
            ': heartbeat',
            '',
        ]);

        $this->assertSame('only this', $this->parse($stream));
    }

    public function testHandlesCarriageReturnsAndAMissingSpaceAfterTheColon(): void
    {
        $stream = "data:{\"message\": \"a\"}\r\n\r\ndata: {\"message\": \"b\"}\r\n\r\n";

        $this->assertSame('ab', $this->parse($stream));
    }

    public function testStopsAtTheDoneSentinel(): void
    {
        $stream = $this->stream('{"message": "kept"}', '[DONE]', '{"message": "dropped"}');

        $this->assertSame('kept', $this->parse($stream));
    }

    public function testParsesTheStreamExactlyAsProductionSendsIt(): void
    {
        // Verbatim shape captured from a live summary/completions response,
        // including the id/event lines and the trailing [DONE].
        $stream = <<<'SSE'
            id: 05962e0f9d5f400e9840bab2a7c6c705
            event: metadata
            data: {"summary_id": "06a7c981c5de74648000d9383e17bcb7"}

            id: 1e397db6e9e944b3bdcc377456e05a93
            event: message
            data: {"message": "#", "conversation_id": ""}

            id: 4d57817fc90b4e9e8f7679d4d6cfa505
            event: message
            data: {"message": " DIY", "conversation_id": ""}

            id: fde1e1ceec2145e497fc104b900cbbbd
            event: message
            data: [DONE]

            SSE;

        $this->assertSame('# DIY', $this->parse($stream));
    }

    public function testTakesANonJsonPayloadLiterally(): void
    {
        $stream = $this->stream('plain text chunk');

        $this->assertSame('plain text chunk', $this->parse($stream));
    }

    public function testReturnsAnEmptyStringForAStreamWithNoFragments(): void
    {
        $this->assertSame('', $this->parse(''));
        $this->assertSame('', $this->parse($this->stream('{"summary_id": "abc"}')));
    }
}
