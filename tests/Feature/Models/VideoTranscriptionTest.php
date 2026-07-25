<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Throwable;
use Tests\TestCase;
use App\Models\Media;
use App\Models\VideoTranscription;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class VideoTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function testUrlInfoStartTranscriptionAndTranscriptionAreCastToArray(): void
    {
        $media = Media::factory()->create();

        $record = VideoTranscription::factory()->create([
            'media_id'            => $media->id,
            'url_info'            => ['code' => 100000],
            'start_transcription' => ['code' => 100000, 'data' => ['audio_id' => 'audio-1']],
            'transcription'       => ['code' => 100000, 'data' => ['versions' => []]],
        ]);

        $fresh = VideoTranscription::find($record->id);

        $this->assertIsArray($fresh->url_info);
        $this->assertIsArray($fresh->start_transcription);
        $this->assertIsArray($fresh->transcription);
        $this->assertSame('audio-1', $fresh->start_transcription['data']['audio_id']);
    }

    public function testBelongsToMedia(): void
    {
        $media = Media::factory()->create();
        $record = VideoTranscription::factory()->create(['media_id' => $media->id]);

        $this->assertTrue($record->media->is($media));
    }

    public function testMediaHasOneVideoTranscription(): void
    {
        $media = Media::factory()->create();
        $record = VideoTranscription::factory()->create(['media_id' => $media->id]);

        $this->assertTrue($media->videoTranscription->is($record));
    }

    public function testMediaIdIsUnique(): void
    {
        $media = Media::factory()->create();
        VideoTranscription::factory()->create(['media_id' => $media->id]);

        $this->expectException(Throwable::class);

        VideoTranscription::factory()->create(['media_id' => $media->id]);
    }
}
