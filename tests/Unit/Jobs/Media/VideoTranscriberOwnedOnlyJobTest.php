<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Mockery;
use Tests\TestCase;
use App\Models\Media;
use App\Models\Caption;
use App\Services\YoutubeService;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Storage;
use App\Jobs\Media\VideoTranscriberFetchJob;
use App\Jobs\Media\VideoTranscriberStartJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesVideoTranscriberOpenApi;

/**
 * transcribe-owned-only 驗收：StartJob 執行時再檢查收錄（S03），轉錄中的影片不受影響（S06）。
 *
 * S03 讓外部服務都能正常回應，再斷言一個都沒被呼叫：失敗時是斷言失敗，不是被擋下的例外。
 *
 * @internal
 * @coversNothing
 */
class VideoTranscriberOwnedOnlyJobTest extends TestCase
{
    use RefreshDatabase;
    use FakesVideoTranscriberOpenApi;

    private int $captionChecks = 0;

    /** 公開字幕檢查開著、這支影片還沒檢查過：沒有收錄檢查的話一定會打 YouTube。 */
    private function uncheckedUncollectedMedia(): Media
    {
        config()->set('services.youtube.require_public_captions', true);

        return Media::factory()->create([
            'status'       => Media::STATUS_CREATED,
            'video_detail' => ['yt:videoId' => self::VIDEO_ID],
        ]);
    }

    /** 數 YouTube 字幕檢查被呼叫幾次；照常回「有字幕」，讓沒有收錄檢查的現行程式碼一路送出。 */
    private function countCaptionChecks(): void
    {
        $youtube = Mockery::mock(YoutubeService::class);
        $youtube->shouldReceive('hasCaptionTracks')->andReturnUsing(function () {
            ++$this->captionChecks;

            return true;
        });
        $this->app->instance(YoutubeService::class, $youtube);
    }

    /** 兩組 API 都會照常回應。 */
    private function fakeBothProviders(): void
    {
        $this->fakeVideoTranscriber(
            ['POST /transcriptions' => $this->created()],
            [
                '*videotranscriber.ai/api/v1/userinfo*'                => $this->ok(['code' => 100000, 'data' => ['user_id' => 1, 'email' => 'qa@example.test', 'user_name' => 'QA']]),
                '*videotranscriber.ai/api/v1/transcriptions/url-info*' => $this->ok(['code' => 100000, 'data' => ['type' => 3, 'title' => 'T', 'audio_time' => 139]]),
                '*videotranscriber.ai/api/v1/transcriptions/start*'    => $this->ok(['code' => 100000, 'data' => ['event_id' => 'e-1', 'audio_id' => 'audio-1']]),
            ]
        );
    }

    private function assertSkippedQuietly(VideoTranscriberStartJob $job, Media $media): void
    {
        $this->assertSame(0, $this->captionChecks, 'no YouTube caption check');
        Http::assertNothingSent();
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status);
        $this->assertArrayNotHasKey('public_captions', (array) $media->video_detail, 'the caption check was not recorded either');
        $this->assertFalse($job->job->isReleased(), 'not released');
        $this->assertFalse($job->job->hasFailed(), 'not failed');
        $this->assertFalse(VideoTranscription::query()->where('media_id', $media->id)->exists());
        foreach (['error', 'critical', 'alert', 'emergency'] as $level) {
            $this->assertSame([], $this->logsAt($level), "no {$level} log");
        }
    }

    public static function providers(): array
    {
        return ['legacy' => ['legacy'], 'openapi' => ['openapi']];
    }

    // ── S03 ───────────────────────────────────────────

    #[DataProvider('providers')]
    public function testS03AnUncollectedMediaIsNotSubmittedWhenTheJobRuns(string $provider): void
    {
        $provider === 'openapi' ? $this->useOpenApi() : $this->useLegacy();
        $this->recordLogs();
        $this->countCaptionChecks();
        $this->fakeBothProviders();
        $media = $this->uncheckedUncollectedMedia();

        $job = $this->runJob(new VideoTranscriberStartJob($media));

        $this->assertSkippedQuietly($job, $media);
    }

    /** qa 邊界：之前被 release（例如名額已滿），重試時影片已沒人收錄。 */
    #[DataProvider('providers')]
    public function testS03ARetriedJobAlsoSkipsAnUncollectedMedia(string $provider): void
    {
        $provider === 'openapi' ? $this->useOpenApi() : $this->useLegacy();
        $this->recordLogs();
        $this->countCaptionChecks();
        $this->fakeBothProviders();
        $media = $this->uncheckedUncollectedMedia();

        $job = $this->runJob(new VideoTranscriberStartJob($media), attempts: 4);

        $this->assertSkippedQuietly($job, $media);
    }

    // ── 部署相容（security T02 r1 待確認）───────────────────

    /**
     * 部署前排進佇列的 payload：當時的 job 還沒有 manual 這個屬性。
     *
     * SerializesModels 會略過「等於宣告預設值」的屬性，所以現行格式可能本來就沒有這個 key；
     * 有的話拿掉，並把物件的屬性數減一。
     */
    private function payloadFromBeforeTheDeploy(VideoTranscriberStartJob $job): string
    {
        $payload = (string) preg_replace('/s:6:"manual";b:0;/', '', serialize($job), -1, $removed);

        if ($removed > 0) {
            $payload = (string) preg_replace_callback(
                '/^O:(\d+):"([^"]+)":(\d+):/',
                fn (array $m) => sprintf('O:%s:"%s":%d:', $m[1], $m[2], (int) $m[3] - $removed),
                $payload
            );
        }

        $this->assertStringNotContainsString('"manual"', $payload);

        return $payload;
    }

    #[DataProvider('providers')]
    public function testAJobQueuedBeforeTheDeployRunsAsAScheduledOne(string $provider): void
    {
        $provider === 'openapi' ? $this->useOpenApi() : $this->useLegacy();
        $this->recordLogs();
        $this->fakeBothProviders();
        $collected = $this->createdMedia();
        $uncollected = $this->createdMedia(owned: false);

        $this->runJob(unserialize($this->payloadFromBeforeTheDeploy(new VideoTranscriberStartJob($collected))));
        $this->runJob(unserialize($this->payloadFromBeforeTheDeploy(new VideoTranscriberStartJob($uncollected))));

        $this->assertSame(Media::STATUS_TRANSCRIBING, $collected->refresh()->status, 'a collected media is submitted');
        $this->assertSame(Media::STATUS_CREATED, $uncollected->refresh()->status, 'an uncollected one is skipped like any scheduled job');
        $this->assertSame([], $this->logsAt('error'));
    }

    // ── S06 ───────────────────────────────────────────

    public function testS06ATranscribingMediaWithoutCollectorsStillGetsItsCaptions(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeVideoTranscriber([
            'GET /transcriptions/' . self::TASK_ID             => $this->task('succeeded'),
            'GET /transcriptions/' . self::TASK_ID . '/result' => $this->ok($this->resultBody([$this->segment(0, 0, 2, 'Hello there')])),
        ]);
        $media = $this->transcribingMedia('openapi');
        $this->assertFalse($media->users()->exists(), 'nobody collects it');

        $this->runJob(new VideoTranscriberFetchJob($media));

        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->refresh()->status);
        $this->assertSame('Hello there', Caption::query()->where('media_id', $media->id)->first()?->text);
    }
}
