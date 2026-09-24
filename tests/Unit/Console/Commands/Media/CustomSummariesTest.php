<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Media;

use Tests\TestCase;
use App\Models\Source;
use App\Models\Summary;
use App\Models\CustomPrompt;
use Hypervel\Support\Facades\Queue;
use App\Jobs\Media\CustomSummaryJob;
use Tests\Concerns\BuildsCustomSummaryScenario;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Console\Commands\Media\CustomSummaries
 */
class CustomSummariesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomSummaryScenario;

    public function testDispatchesForANewVideoOnABoundSource(): void
    {
        Queue::fake();
        $this->givenAPaidUserWithABoundSource();
        $this->givenACaptionedVideo();

        $this->artisan('media:custom-summaries')->run();

        Queue::assertPushed(CustomSummaryJob::class, 1);
    }

    /**
     * 綁定之前就存在的影片不補。來源可能已有數百支影片，全部補摘要是一大筆
     * 推論成本，而且使用者沒有要求。
     */
    public function testDoesNotBackfillVideosFromBeforeTheBinding(): void
    {
        Queue::fake();
        $this->givenAPaidUserWithABoundSource();
        $this->givenACaptionedVideo(now()->subDays(3)->toDateTimeString());

        $this->artisan('media:custom-summaries')->run();

        Queue::assertNotPushed(CustomSummaryJob::class);
    }

    /** 方案沒開放自訂摘要（降級、到期）就不派。 */
    public function testSkipsUsersWhosePlanDoesNotAllowCustomSummaries(): void
    {
        Queue::fake();
        $this->givenAPaidUserWithABoundSource(customSummaryEnabled: false);
        $this->givenACaptionedVideo();

        $this->artisan('media:custom-summaries')->run();

        Queue::assertNotPushed(CustomSummaryJob::class);
    }

    /** 取消訂閱該來源後就不再為它產生。 */
    public function testSkipsSourcesTheUserNoLongerFollows(): void
    {
        Queue::fake();
        $this->givenAPaidUserWithABoundSource();
        $this->user->sources()->detach($this->source->id);
        $this->givenACaptionedVideo();

        $this->artisan('media:custom-summaries')->run();

        Queue::assertNotPushed(CustomSummaryJob::class);
    }

    /**
     * 已有摘要列（不論完成或失敗）就不重派。失敗的由 job 自己重試，重試用盡就停；
     * 這裡若重派，壞掉的影片會每分鐘被派一次。
     */
    public function testDoesNotRedispatchOnceASummaryRowExists(): void
    {
        Queue::fake();
        $this->givenAPaidUserWithABoundSource();
        $media = $this->givenACaptionedVideo();

        foreach ([Summary::STATUS_COMPLETED, Summary::STATUS_FAILED] as $status) {
            $media->summaries()->delete();
            $media->summaries()->create(['user_id' => $this->user->id, 'locale' => 'en', 'status' => $status]);

            $this->artisan('media:custom-summaries')->run();

            Queue::assertNotPushed(CustomSummaryJob::class);
        }
    }

    /** 全站共用的摘要（user_id = null）不算「這個人已有摘要」。 */
    public function testASharedSummaryDoesNotCountAsTheUsersOwn(): void
    {
        Queue::fake();
        $this->givenAPaidUserWithABoundSource();
        $media = $this->givenACaptionedVideo();
        $media->summaries()->create(['user_id' => null, 'locale' => 'en', 'status' => Summary::STATUS_COMPLETED]);

        $this->artisan('media:custom-summaries')->run();

        Queue::assertPushed(CustomSummaryJob::class, 1);
    }

    /** 沒綁定的來源不派。 */
    public function testIgnoresUnboundSources(): void
    {
        Queue::fake();
        $this->givenAPaidUserWithABoundSource();
        $other = Source::factory()->create();
        $this->user->sources()->attach($other->id);
        $this->givenACaptionedVideo(source: $other);

        $this->artisan('media:custom-summaries')->run();

        Queue::assertNotPushed(CustomSummaryJob::class);
    }

    /** 同一人的兩個提示詞綁了同一個來源，同一支影片只派一次。 */
    public function testDispatchesOncePerVideoEvenWithTwoPromptsOnTheSameSource(): void
    {
        Queue::fake();
        $this->givenAPaidUserWithABoundSource();
        CustomPrompt::query()->create([
            'user_id' => $this->user->id,
            'title'   => 'Second',
            'content' => 'Another prompt.',
        ])->sources()->attach($this->source->id);
        $this->givenACaptionedVideo();

        $this->artisan('media:custom-summaries')->run();

        Queue::assertPushed(CustomSummaryJob::class, 1);
    }
}
