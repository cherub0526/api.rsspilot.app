<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Support\Facades\Queue;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Jobs\Media\VideoTranscriberSmartSummaryJob;

/**
 * @internal
 * @covers \App\Console\Commands\VideoTranscriber\Summarize
 */
class SummarizeTest extends TestCase
{
    use RefreshDatabase;

    public function testDispatchesAJobForEveryTranscribedMedia(): void
    {
        Queue::fake();

        $transcribed1 = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);
        $transcribed2 = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);
        $notTranscribed = Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:summary')->run();

        Queue::assertPushed(VideoTranscriberSmartSummaryJob::class, 2);
        Queue::assertPushed(fn (VideoTranscriberSmartSummaryJob $job) => $job->uniqueId() === $transcribed1->id);
        Queue::assertPushed(fn (VideoTranscriberSmartSummaryJob $job) => $job->uniqueId() === $transcribed2->id);
        Queue::assertNotPushed(fn (VideoTranscriberSmartSummaryJob $job) => $job->uniqueId() === $notTranscribed->id);
    }

    public function testIdOptionOnlyDispatchesTheMatchingMedia(): void
    {
        Queue::fake();

        $target = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);
        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $this->artisan('videotranscriber:summary', ['--id' => $target->id])->run();

        Queue::assertPushed(VideoTranscriberSmartSummaryJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberSmartSummaryJob $job) => $job->uniqueId() === $target->id);
    }

    public function testIdOptionIgnoresTheMediaStatus(): void
    {
        Queue::fake();

        $failed = Media::factory()->create(['status' => Media::STATUS_SUMMARIZE_FAILED]);

        $this->artisan('videotranscriber:summary', ['--id' => $failed->id])->run();

        Queue::assertPushed(VideoTranscriberSmartSummaryJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberSmartSummaryJob $job) => $job->uniqueId() === $failed->id);
    }

    public function testQueuesOnTheSmartSummaryQueue(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $this->artisan('videotranscriber:summary')->run();

        Queue::assertPushed(
            fn (VideoTranscriberSmartSummaryJob $job) => $job->queue === 'videotranscriber.smart-summary'
        );
    }

    public function testDoesNothingWhenNoMediaIsTranscribed(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:summary')->run();

        Queue::assertNothingPushed();
    }
}
