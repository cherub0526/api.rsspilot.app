<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Queue;
use App\Jobs\Media\VideoTranscriberStartJob;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesVideoTranscriberOpenApi;

/**
 * transcribe-owned-only 驗收：`videotranscriber:start` 只派有人收錄（或免費來源）的影片。
 *
 * 走 openapi provider，指令不需要 legacy 登入。要看 job 有沒有送出時，取出排入的 job 直接執行，
 * 不依賴「人工指定」旗標的實作方式。
 *
 * @internal
 * @coversNothing
 */
class StartOwnedOnlyTest extends TestCase
{
    use RefreshDatabase;
    use FakesVideoTranscriberOpenApi;

    private function media(string $status = Media::STATUS_CREATED, ?Source $source = null, ?string $id = null): Media
    {
        return Media::factory()->create(array_filter([
            'id'           => $id,
            'status'       => $status,
            'source_id'    => $source?->id,
            'video_detail' => ['yt:videoId' => self::VIDEO_ID, 'public_captions' => true],
        ], fn ($value) => $value !== null));
    }

    private function collectedBy(Media $media, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $media->users()->attach($user->id);

        return $user;
    }

    private function freeSource(): Source
    {
        return Source::factory()->create(['free' => true]);
    }

    /** @return list<string> */
    private function dispatchedIds(): array
    {
        return Queue::pushed(VideoTranscriberStartJob::class)
            ->map(fn (VideoTranscriberStartJob $job) => $job->uniqueId())->values()->all();
    }

    private function pushedStartJob(): VideoTranscriberStartJob
    {
        $jobs = Queue::pushed(VideoTranscriberStartJob::class);
        $this->assertCount(1, $jobs, 'the command dispatched one StartJob');

        return $jobs->first();
    }

    private function start(array $options = []): int
    {
        return $this->artisan('videotranscriber:start', $options)->run();
    }

    private function assertSubmitted(Media $media): void
    {
        $this->assertAskedOpenApi('POST', '/transcriptions');
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->refresh()->status);
    }

    private function assertNotSubmitted(Media $media): void
    {
        $this->assertSame([], $this->openApiRequests());
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status);
    }

    // ── S01、S02 ──────────────────────────────────────

    public function testS01OnlyCollectedMediaIsDispatched(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber();
        $a = $this->media();
        $this->collectedBy($a);
        $b = $this->media();

        $this->assertSame(0, $this->start());

        $this->assertSame([$a->id], $this->dispatchedIds());
        $this->assertNotContains($b->id, $this->dispatchedIds());
    }

    public function testS02MediaCollectedLaterIsDispatchedOnTheNextRun(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber();
        $b = $this->media();

        $this->start();
        $this->assertSame([], $this->dispatchedIds(), 'nobody collected it yet');

        $this->collectedBy($b);
        $this->start();

        $this->assertSame([$b->id], $this->dispatchedIds());
    }

    // ── S04 --id ──────────────────────────────────────

    public function testS04IdDispatchesAnUncollectedMediaAndItIsSubmitted(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $b = $this->media();

        $this->start(['--id' => $b->id]);
        $this->runJob($this->pushedStartJob());

        $this->assertSubmitted($b);
    }

    /** qa 邊界：真的 queue 會序列化 job，「人工指定」要跟著還原。 */
    public function testS04TheManualDispatchSurvivesSerialization(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $b = $this->media();

        $this->start(['--id' => $b->id]);
        $this->runJob(unserialize(serialize($this->pushedStartJob())));

        $this->assertSubmitted($b);
    }

    // ── S07 名額 ──────────────────────────────────────

    public function testS07UncollectedMediaDoesNotTakeTheLastSlot(): void
    {
        $this->useOpenApi();
        config()->set('services.videotranscriber.max_concurrent', 2);
        Queue::fake();
        $this->fakeVideoTranscriber();
        $this->media(Media::STATUS_TRANSCRIBING);
        $b = $this->media(id: '01jaaaaaaaaaaaaaaaaaaaaaa1');
        $a = $this->media(id: '01jaaaaaaaaaaaaaaaaaaaaaa2');
        $this->collectedBy($a);

        $this->start();

        $this->assertSame([$a->id], $this->dispatchedIds());
        $this->assertNotContains($b->id, $this->dispatchedIds(), 'B takes no slot');
    }

    // ── S08 免費來源 ────────────────────────────────────

    public function testS08FreeSourceMediaIsDispatchedAndSubmittedWithoutACollector(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $media = $this->media(source: $this->freeSource());

        $this->start();
        $this->runJob($this->pushedStartJob());

        $this->assertSubmitted($media);
    }

    /** qa 邊界：來源存在但不是免費，沒人收錄就不派。 */
    public function testANonFreeSourceDoesNotCountAsCollected(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber();
        $this->media(source: Source::factory()->create(['free' => false]));

        $this->start();

        $this->assertSame([], $this->dispatchedIds());
    }

    /** SR-5：OR 條件沒包進同一組時，免費來源底下其他狀態的影片會每分鐘被重送。 */
    public function testSr5FreeSourceMediaInOtherStatusesIsNotDispatched(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber();
        $source = $this->freeSource();
        $this->media(Media::STATUS_TRANSCRIBE_FAILED, $source);
        $this->media(Media::STATUS_TRANSCRIBED, $source);
        $this->collectedBy($this->media(Media::STATUS_TRANSCRIBE_FAILED));

        $this->start();

        $this->assertSame([], $this->dispatchedIds());
    }

    /** SR-6.3：派工後來源改回不免費、又沒人收錄，job 不送出。 */
    public function testSr6AJobSkipsWhenTheSourceIsNoLongerFree(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $source = $this->freeSource();
        $media = $this->media(source: $source);

        $this->start();
        $job = $this->pushedStartJob();
        $source->fill(['free' => false])->save();
        $this->runJob($job);

        $this->assertNotSubmitted($media);
    }

    // ── S09 已刪除帳號 ──────────────────────────────────

    public function testS09MediaCollectedOnlyByADeletedAccountIsNotDispatched(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber();
        $media = $this->media();
        $this->collectedBy($media)->delete();

        $this->start();

        $this->assertNotContains($media->id, $this->dispatchedIds());
    }

    /** qa 邊界：還有一位未刪除的收錄者就照常派。 */
    public function testS09ADeletedCollectorDoesNotHideALiveOne(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber();
        $media = $this->media();
        $this->collectedBy($media)->delete();
        $this->collectedBy($media);

        $this->start();

        $this->assertSame([$media->id], $this->dispatchedIds());
    }

    /** qa 邊界：派工後唯一的收錄者刪除帳號，job 不送出。 */
    public function testS09AJobSkipsWhenTheOnlyCollectorDeletedTheAccount(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $media = $this->media();
        $user = $this->collectedBy($media);

        $this->start();
        $job = $this->pushedStartJob();
        $user->delete();
        $this->runJob($job);

        $this->assertNotSubmitted($media);
    }

    /**
     * qa 邊界（SR-4 × S24）：跳過時不動上一次沒拿到任務 id 的 Idempotency-Key，
     * 之後有人收錄、下一輪送出時沿用它，不會在官方那邊多建一個任務。
     */
    public function testASkippedJobKeepsTheUnansweredIdempotencyKeyForTheNextRun(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $media = $this->media();
        $key = 'rsspilot-media-' . $media->id . '-01jqaqaqaqaqaqaqaqaqaqaqaq';
        VideoTranscription::factory()->create([
            'media_id'            => $media->id,
            'url_info'            => null,
            'start_transcription' => ['provider' => 'openapi', VideoTranscriberStartJob::IDEMPOTENCY_KEY => $key],
        ]);

        $this->runJob(new VideoTranscriberStartJob($media));
        $this->assertNotSubmitted($media);

        $this->collectedBy($media);
        $this->start();
        $this->runJob($this->pushedStartJob());

        $this->assertSubmitted($media);
        $this->assertSame([$key], $this->idempotencyKeys());
    }

    // ── S10 排程派出的不是人工指定 ─────────────────────────

    public function testS10AScheduledJobIsNotManual(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $media = $this->media();
        $user = $this->collectedBy($media);

        $this->start();
        $job = $this->pushedStartJob();
        $media->users()->detach($user->id);
        $this->runJob($job);

        $this->assertNotSubmitted($media);
    }
}
