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

    public function testIdOptionIgnoresTheMediaStatus(): void
    {
        Queue::fake();

        $failed = Media::factory()->create(['status' => Media::STATUS_TRANSCRIBE_FAILED]);

        $this->artisan('videotranscriber:start', ['--id' => $failed->id])->run();

        Queue::assertPushed(VideoTranscriberStartJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $failed->id);
    }

    public function testDoesNothingWhenNoMediaIsCreated(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $this->artisan('videotranscriber:start')->run();

        Queue::assertNothingPushed();
    }

    public function testDispatchesNothingWhenAlreadyAtTheConcurrentLimit(): void
    {
        Queue::fake();

        Media::factory()->count(5)->create(['status' => Media::STATUS_TRANSCRIBING]);
        Media::factory()->count(3)->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:start')->run();

        Queue::assertNothingPushed();
    }

    public function testOnlyDispatchesUpToTheRemainingSlots(): void
    {
        Queue::fake();

        Media::factory()->count(3)->create(['status' => Media::STATUS_TRANSCRIBING]);
        Media::factory()->count(4)->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:start')->run();

        // 5 個名額扣掉 3 個在途，只剩 2 個。其餘留給下一次執行。
        Queue::assertPushed(VideoTranscriberStartJob::class, 2);
    }

    public function testIdOptionBypassesTheConcurrentLimit(): void
    {
        Queue::fake();

        Media::factory()->count(5)->create(['status' => Media::STATUS_TRANSCRIBING]);
        $target = Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:start', ['--id' => $target->id])->run();

        // 指名 media 是明確的人工覆寫，跟略過狀態篩選同樣的理由。
        Queue::assertPushed(VideoTranscriberStartJob::class, 1);
        Queue::assertPushed(fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $target->id);
    }
}
