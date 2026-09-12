<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Support\Facades\Queue;
use App\Jobs\Media\VideoTranscriberArchiveJob;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Console\Commands\VideoTranscriber\Archive
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function testDispatchesAJobForTheNamedMedia(): void
    {
        Queue::fake();

        $media = Media::factory()->create(['status' => Media::STATUS_SUMMARIZED]);

        $this->artisan('videotranscriber:archive', ['--id' => $media->id])->run();

        Queue::assertPushed(VideoTranscriberArchiveJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberArchiveJob $job) => $job->uniqueId() === $media->id);
    }

    public function testRequiresAnId(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        // Without --id this would be a bulk backfill, and every media pulls a
        // multi-MB mp3 — that has to be asked for, not defaulted into.
        $this->artisan('videotranscriber:archive')->run();

        Queue::assertNothingPushed();
    }

    public function testDoesNothingWhenTheMediaDoesNotExist(): void
    {
        Queue::fake();

        $this->artisan('videotranscriber:archive', ['--id' => 'no-such-media'])->run();

        Queue::assertNothingPushed();
    }
}
