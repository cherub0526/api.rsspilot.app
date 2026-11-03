<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Queue;
use App\Jobs\Media\VideoTranscriberStartJob;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesVideoTranscriberOpenApi;

/**
 * videotranscriber-provider-switch 驗收：`videotranscriber:start`（S13、S22）與跨 dispatch 的
 * Idempotency-Key（S15、S24）。
 *
 * S15、S24 走黑箱：先真的跑一次 StartJob，再執行指令、取出排入的 job 再跑一次，比對兩次送出的
 * key。key 存在 start_transcription 的哪個欄位由 backend 決定，測試不依賴。
 *
 * @internal
 * @coversNothing
 */
class StartProviderTest extends TestCase
{
    use RefreshDatabase;
    use FakesVideoTranscriberOpenApi;

    private function pushedStartJob(): VideoTranscriberStartJob
    {
        $jobs = Queue::pushed(VideoTranscriberStartJob::class);
        $this->assertCount(1, $jobs, 'the command dispatched one StartJob');

        return $jobs->first();
    }

    // ── S13、S22 ──────────────────────────────────────

    public function testS13OpenApiModeSkipsTheLegacyLoginCheck(): void
    {
        $this->useOpenApi();
        config()->set('services.videotranscriber.email', null);
        config()->set('services.videotranscriber.password', null);
        Queue::fake();
        $this->fakeVideoTranscriber(others: ['*' => $this->ok(['code' => 100002], 401)]);
        $media = $this->createdMedia();

        $exitCode = $this->artisan('videotranscriber:start')->run();

        $this->assertSame(0, $exitCode);
        Http::assertNothingSent();
        Queue::assertPushed(VideoTranscriberStartJob::class, fn (VideoTranscriberStartJob $job) => $job->uniqueId() === $media->id);
    }

    public function testS22AnUnknownProviderStopsTheCommand(): void
    {
        config()->set('services.videotranscriber.provider', 'openAPI');
        config()->set('services.videotranscriber.api_key', self::API_KEY);
        $this->recordLogs();
        Queue::fake();
        $this->fakeVideoTranscriber(others: ['*' => $this->ok(['code' => 100000, 'data' => []])]);
        $this->createdMedia();

        $exitCode = $this->artisan('videotranscriber:start')->run();

        $this->assertNotSame(0, $exitCode);
        $this->assertNotEmpty($this->logsAt('error'));
        Queue::assertNotPushed(VideoTranscriberStartJob::class);
        Http::assertNothingSent();
    }

    // ── S15 手動 --id 換新 key ───────────────────────────

    public function testS15ManualRedispatchAfterAFailedSubmissionUsesANewKey(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => Http::sequence()
            ->push(['error' => ['code' => 'unsupported_source_url', 'message' => 'x', 'request_id' => 'r', 'retryable' => false, 'details' => null]], 400)
            ->push(['id' => 'task_002', 'object' => 'transcription', 'status' => 'queued'], 202)]);
        $media = $this->createdMedia();
        $this->runJob(new VideoTranscriberStartJob($media));
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);

        $this->artisan('videotranscriber:start', ['--id' => $media->id])->run();
        $this->runJob($this->pushedStartJob());

        [$before, $after] = $this->idempotencyKeys();
        $this->assertNotSame($before, $after);
    }

    public function testS15ManualRedispatchAfterAFailedTranscriptionUsesANewKey(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => Http::sequence()
            ->push(['id' => 'task_001', 'object' => 'transcription', 'status' => 'queued'], 202)
            ->push(['id' => 'task_002', 'object' => 'transcription', 'status' => 'queued'], 202)]);
        $media = $this->createdMedia();
        $this->runJob(new VideoTranscriberStartJob($media));
        // FetchJob 後來把它標失敗（例如 no_speech_detected）
        $media->refresh()->fill(['status' => Media::STATUS_TRANSCRIBE_FAILED])->save();

        $this->artisan('videotranscriber:start', ['--id' => $media->id])->run();
        $this->runJob($this->pushedStartJob());

        [$before, $after] = $this->idempotencyKeys();
        $this->assertNotSame($before, $after);
    }

    // ── S24 排程重派沿用沒拿到任務 id 的 key ──────────────

    public function testS24ScheduledRedispatchReusesAKeyThatNeverGotATaskId(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => Http::sequence()
            ->pushFailedConnection()
            ->push(['id' => 'task_001', 'object' => 'transcription', 'status' => 'queued'], 202)]);
        $media = $this->createdMedia();
        // 送出後連線中斷：job 被 release，之後重試次數用完，影片仍是 created
        $first = $this->runJob(new VideoTranscriberStartJob($media));
        $this->assertTrue($first->job->isReleased());
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status);

        $this->artisan('videotranscriber:start')->run();
        $this->runJob($this->pushedStartJob());

        [$before, $after] = $this->idempotencyKeys();
        $this->assertSame($before, $after, 'the task may already exist under the first key');
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->refresh()->status);
    }

    /** S24 的註解：已經拿到任務 id 的 key 不再沿用。 */
    public function testS24AKeyThatAlreadyGotATaskIdIsNotReused(): void
    {
        $this->useOpenApi();
        Queue::fake();
        $this->fakeVideoTranscriber(['POST /transcriptions' => Http::sequence()
            ->push(['id' => 'task_001', 'object' => 'transcription', 'status' => 'queued'], 202)
            ->push(['id' => 'task_002', 'object' => 'transcription', 'status' => 'queued'], 202)]);
        $media = $this->createdMedia();
        $this->runJob(new VideoTranscriberStartJob($media));
        $media->refresh()->fill(['status' => Media::STATUS_CREATED])->save();

        $this->artisan('videotranscriber:start')->run();
        $this->runJob($this->pushedStartJob());

        [$before, $after] = $this->idempotencyKeys();
        $this->assertNotSame($before, $after);
    }

    /** pm 裁定（T02 r1）：provider 是空字串時視同 legacy，指令照常派工，job 走 legacy。 */
    public function testAnEmptyProviderDispatchesAndSubmitsThroughLegacy(): void
    {
        config()->set('services.videotranscriber.provider', '');
        Queue::fake();
        $this->fakeVideoTranscriber(others: [
            '*videotranscriber.ai/api/v1/userinfo*'                => $this->ok(['code' => 100000, 'data' => ['user_id' => 1, 'email' => 'qa@example.test', 'user_name' => 'QA']]),
            '*videotranscriber.ai/api/v1/transcriptions/url-info*' => $this->ok(['code' => 100000, 'data' => ['type' => 3, 'title' => 'T', 'audio_time' => 139]]),
            '*videotranscriber.ai/api/v1/transcriptions/start*'    => $this->ok(['code' => 100000, 'data' => ['event_id' => 'e-1', 'audio_id' => 'audio-1']]),
        ]);
        $media = $this->createdMedia();

        $this->assertSame(0, $this->artisan('videotranscriber:start')->run());
        $this->runJob($this->pushedStartJob());

        $this->assertSame([], $this->openApiRequests());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/transcriptions/start'));
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->refresh()->status);
    }
}
