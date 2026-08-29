<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\TestCase;
use App\Models\Caption;

/**
 * @internal
 * @covers \App\Models\Caption
 */
class CaptionTranscriptTest extends TestCase
{
    public function testGroupsSegmentsIntoBlocksOfAtLeastThirtySeconds(): void
    {
        $caption = $this->caption([
            ['start' => 0.329, 'end' => 4.331, 'text' => 'first'],
            ['start' => 5.072, 'end' => 29.9, 'text' => 'second'],
            ['start' => 30.1, 'end' => 34.2, 'text' => 'third'],
            ['start' => 35.0, 'end' => 40.0, 'text' => 'fourth'],
        ]);

        $this->assertSame(
            "[00:00:00 ~ 00:00:34] first\nsecond\nthird\n[00:00:35 ~ 00:00:40] fourth",
            $caption->timestampedTranscript()
        );
    }

    public function testFormatsHoursAndPadsEveryField(): void
    {
        $caption = $this->caption([
            ['start' => 3661.0, 'end' => 3725.0, 'text' => 'late'],
        ]);

        $this->assertSame('[01:01:01 ~ 01:02:05] late', $caption->timestampedTranscript());
    }

    public function testSkipsEmptySegmentsAndKeepsTheBlockWindow(): void
    {
        $caption = $this->caption([
            ['start' => 0.0, 'end' => 2.0, 'text' => '  '],
            ['start' => 2.0, 'end' => 6.0, 'text' => ' padded '],
        ]);

        $this->assertSame('[00:00:02 ~ 00:00:06] padded', $caption->timestampedTranscript());
    }

    public function testIgnoresTheExtraKeysGroqStores(): void
    {
        $caption = $this->caption([
            ['id' => 0, 'seek' => 0, 'start' => 0.0, 'end' => 1.12, 'text' => 'whisper', 'tokens' => [1, 2]],
        ]);

        $this->assertSame('[00:00:00 ~ 00:00:01] whisper', $caption->timestampedTranscript());
    }

    public function testReturnsAnEmptyStringWithoutUsableSegments(): void
    {
        $this->assertSame('', $this->caption([])->timestampedTranscript());
        $this->assertSame('', $this->caption(null)->timestampedTranscript());
    }

    public function testBlockSecondsIsConfigurable(): void
    {
        $caption = $this->caption([
            ['start' => 0.0, 'end' => 20.0, 'text' => 'one'],
            ['start' => 20.0, 'end' => 40.0, 'text' => 'two'],
        ]);

        $this->assertSame(
            "[00:00:00 ~ 00:00:20] one\n[00:00:20 ~ 00:00:40] two",
            $caption->timestampedTranscript(10)
        );
    }

    /**
     * @param null|array<int, array<string, mixed>> $segments
     */
    private function caption(?array $segments): Caption
    {
        $caption = new Caption();
        $caption->setAttribute('segments', $segments);

        return $caption;
    }
}
