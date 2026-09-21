<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Media;
use ReflectionProperty;
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

    /**
     * 不帶 --language 時指令不再預設 en——語言交給 job 跟字幕對齊。
     */
    public function testDispatchesWithoutALanguageSoTheJobFollowsTheCaption(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $this->artisan('videotranscriber:summary')->assertExitCode(0);

        Queue::assertPushed(
            VideoTranscriberSmartSummaryJob::class,
            fn (VideoTranscriberSmartSummaryJob $job) => $this->languageOf($job) === null
        );
    }

    /** --language 仍然是明確覆寫，重跑成別的語言時用得上。 */
    public function testLanguageOptionOverridesTheCaptionLanguage(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_TRANSCRIBED]);

        $this->artisan('videotranscriber:summary', ['--language' => 'zh-TW'])->assertExitCode(0);

        Queue::assertPushed(
            VideoTranscriberSmartSummaryJob::class,
            fn (VideoTranscriberSmartSummaryJob $job) => $this->languageOf($job) === 'zh-TW'
        );
    }

    /** job 的語言是 protected，測試用反射讀它。 */
    private function languageOf(VideoTranscriberSmartSummaryJob $job): ?string
    {
        $property = new ReflectionProperty($job, 'languageCode');
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    public function testDoesNothingWhenNoMediaIsTranscribed(): void
    {
        Queue::fake();

        Media::factory()->create(['status' => Media::STATUS_CREATED]);

        $this->artisan('videotranscriber:summary')->run();

        Queue::assertNothingPushed();
    }
}
