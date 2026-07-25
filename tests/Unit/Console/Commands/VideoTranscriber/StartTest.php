<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Support\Facades\Queue;
use App\Jobs\Media\VideoTranscriberStartJob;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Console\Commands\VideoTranscriber\Start
 */
class StartTest extends TestCase
{
    use RefreshDatabase;

    public function testDispatchesAJobForEveryCreatedMedia(): void
    {
        Queue::fake();

        $created1 = Media::factory()->create(['status' => Media::STATUS_CREATED]);
        $created2 = Media::factory()->create(['status' => Media::STATUS_CREATED]);
        $notCreated = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBING]);

        $this->artisan('videotranscriber:start')->run();

        Queue::assertPushed(VideoTranscriberStartJob::class, 2);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $created1->id);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $created2->id);
        Queue::assertNotPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $notCreated->id);
    }

    public function testIdOptionOnlyDispatchesTheMatchingMedia(): void
    {
        Queue::fake();

        $target = Media::factory()->create(['status' => Media::STATUS_CREATED]);
        Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:start', ['--id' => $target->id])->run();

        Queue::assertPushed(VideoTranscriberStartJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $target->id);
    }

    public function testDoesNothingWhenNoMediaIsCreated(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $this->artisan('videotranscriber:start')->run();

        Queue::assertNothingPushed();
    }
}
