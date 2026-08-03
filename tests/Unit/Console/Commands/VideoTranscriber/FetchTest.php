<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Support\Facades\Queue;
use App\Jobs\Media\VideoTranscriberFetchJob;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Console\Commands\VideoTranscriber\Fetch
 */
class FetchTest extends TestCase
{
    use RefreshDatabase;

    public function testDispatchesAJobForEveryTranscribingMedia(): void
    {
        Queue::fake();

        $transcribing1 = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);
        $transcribing2 = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);
        $notTranscribing = Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:fetch')->run();

        Queue::assertPushed(VideoTranscriberFetchJob::class, 2);
        Queue::assertPushed(fn (VideoTranscriberFetchJob $job) => $job->uniqueId() === $transcribing1->id);
        Queue::assertPushed(fn (VideoTranscriberFetchJob $job) => $job->uniqueId() === $transcribing2->id);
        Queue::assertNotPushed(fn (VideoTranscriberFetchJob $job) => $job->uniqueId() === $notTranscribing->id);
    }

    public function testIdOptionOnlyDispatchesTheMatchingMedia(): void
    {
        Queue::fake();

        $target = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);
        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);

        $this->artisan('videotranscriber:fetch', ['--id' => $target->id])->run();

        Queue::assertPushed(VideoTranscriberFetchJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberFetchJob $job) => $job->uniqueId() === $target->id);
    }

    public function testIdOptionIgnoresTheMediaStatus(): void
    {
        Queue::fake();

        $failed = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBE_FAILED]);

        $this->artisan('videotranscriber:fetch', ['--id' => $failed->id])->run();

        Queue::assertPushed(VideoTranscriberFetchJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberFetchJob $job) => $job->uniqueId() === $failed->id);
    }

    public function testDoesNothingWhenNoMediaIsTranscribing(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:fetch')->run();

        Queue::assertNothingPushed();
    }
}
