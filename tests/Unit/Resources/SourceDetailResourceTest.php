<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use Tests\TestCase;
use App\Models\Source;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Http\Resources\SourceDetailResource;

/**
 * @internal
 * @coversNothing
 */
class SourceDetailResourceTest extends TestCase
{
    use RefreshDatabase;

    // 5.6 SourceDetailResource 對 null description 回傳 ""
    public function testDescriptionFallsBackToEmptyString(): void
    {
        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
            'description' => null,
            'metadata'    => [],
        ]);

        $result = (new SourceDetailResource($source))->resolve();

        $this->assertSame('', $result['description']);
    }

    // 5.7 SourceDetailResource 對播放清單類型的 subscriber_count 回傳 null
    public function testSubscriberCountIsNullForPlaylist(): void
    {
        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_PLAYLIST,
            'external_id' => 'PLxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'metadata'    => ['subscriber_count' => 999999],
        ]);

        $result = (new SourceDetailResource($source))->resolve();

        $this->assertNull($result['subscriber_count']);
    }

    public function testSubscriberCountFallsBackToZeroForChannel(): void
    {
        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
            'metadata'    => [],
        ]);

        $result = (new SourceDetailResource($source))->resolve();

        $this->assertSame(0, $result['subscriber_count']);
    }
}
