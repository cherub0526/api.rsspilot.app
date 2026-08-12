<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Support\Facades\DB;
use App\Models\VideoTranscription;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class ModelJsonEncodingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Read the column without going through the cast — a round-trip would
     * decode the escapes and hide what was actually written.
     */
    private function rawTranscription(string $id): string
    {
        return DB::table('video_transcriptions')->where('id', $id)->value('transcription');
    }

    public function testJsonCastsKeepUnicodeUnescaped(): void
    {
        $media = Media::factory()->create();

        $record = VideoTranscription::factory()->create([
            'media_id'      => $media->id,
            'transcription' => ['text' => '我不相信啦。'],
        ]);

        $raw = $this->rawTranscription($record->id);

        $this->assertStringContainsString('我不相信啦。', $raw);
        $this->assertStringNotContainsString('\u', $raw);
    }

    public function testJsonCastsKeepSlashesUnescaped(): void
    {
        $media = Media::factory()->create();

        $record = VideoTranscription::factory()->create([
            'media_id'      => $media->id,
            'transcription' => ['subtitle_url' => 'https://cdn.ng-resource.com/origin.txt'],
        ]);

        $raw = $this->rawTranscription($record->id);

        $this->assertStringContainsString('https://cdn.ng-resource.com/origin.txt', $raw);
        $this->assertStringNotContainsString('\/', $raw);
    }

    public function testEscapedRowsWrittenBeforeTheOverrideStillDecode(): void
    {
        $media = Media::factory()->create();
        $record = VideoTranscription::factory()->create(['media_id' => $media->id]);

        // Exactly what the framework default would have written.
        DB::table('video_transcriptions')
            ->where('id', $record->id)
            ->update(['transcription' => json_encode(['text' => '我不相信啦。'])]);

        $fresh = VideoTranscription::find($record->id);

        $this->assertSame('我不相信啦。', $fresh->transcription['text']);
    }
}
