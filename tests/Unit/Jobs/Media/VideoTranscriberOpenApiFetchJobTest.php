<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\Media;
use App\Models\Caption;
use Hypervel\HttpClient\Request;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Storage;
use App\Jobs\Media\VideoTranscriberFetchJob;
use App\Jobs\Media\VideoTranscriberArchiveJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesVideoTranscriberOpenApi;

/**
 * videotranscriber-provider-switch 驗收：FetchJob 與 ArchiveJob
 * （S03、S03b、S04–S07、S08／S09／S11 的 FetchJob 列、S14、S18、S19、S21、S23）。
 *
 * 送出時的紀錄沿用 plan 的形狀：`start_transcription = {provider, code: 100000, data: {audio_id}}`。
 *
 * @internal
 * @coversNothing
 */
class VideoTranscriberOpenApiFetchJobTest extends TestCase
{
    use RefreshDatabase;
    use FakesVideoTranscriberOpenApi;

    private const STATUS_PATH = 'GET /transcriptions/' . self::TASK_ID;

    private const RESULT_PATH = 'GET /transcriptions/' . self::TASK_ID . '/result';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Queue::fake();
    }

    private function fetch(Media $media, int $attempts = 1): VideoTranscriberFetchJob
    {
        /** @var VideoTranscriberFetchJob */
        return $this->runJob(new VideoTranscriberFetchJob($media), $attempts);
    }

    /** 標準的三段：中間那段是空白，要被略過。 */
    private function segments(): array
    {
        return [
            $this->segment(0, 0.0, 2.5, ' Hello there '),
            $this->segment(1, 2.5, 3.0, '   '),
            $this->segment(2, 3.0, 5.25, 'General Kenobi'),
        ];
    }

    /** openapi 成功：狀態 succeeded，/result 回指定內容。 */
    private function fakeSucceeded(array $result, string $status = 'succeeded'): void
    {
        $this->fakeVideoTranscriber([
            self::STATUS_PATH => $this->task($status),
            self::RESULT_PATH => $this->ok($result),
        ]);
    }

    private function archived(Media $media): string
    {
        return (string) Storage::disk('s3')->get(sprintf('videotranscriber.ai/%s/transcribe.json', $media->id));
    }

    private function caption(Media $media): ?Caption
    {
        return Caption::query()->where('media_id', $media->id)->first();
    }

    private function assertStillWaiting(VideoTranscriberFetchJob $job, Media $media, int $delay, string $message = ''): void
    {
        $this->assertTrue($job->job->isReleased(), $message);
        $this->assertSame($delay, $job->job->releaseDelay, $message);
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->refresh()->status, $message);
    }

    // ── S03、S03b 寫入 ─────────────────────────────────

    public function testS03ASucceededTaskWritesCaptionsDurationAndTheArchive(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeSucceeded($this->resultBody($this->segments(), 'en', 138.6));
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        $caption = $this->caption($media);
        $this->assertNotNull($caption);
        $this->assertEquals([
            ['start' => 0, 'end' => 2.5, 'text' => 'Hello there'],
            ['start' => 3, 'end' => 5.25, 'text' => 'General Kenobi'],
        ], $caption->segments, 'no speaker or id, blank text skipped');
        $this->assertSame('Hello there General Kenobi', $caption->text);
        $this->assertSame(Caption::LOCAL_EN, $caption->locale);
        $this->assertTrue((bool) $caption->primary);

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->status);
        $this->assertSame(139, $media->duration, 'round(138.6)');
        $this->assertSame(Caption::LOCAL_EN, $media->language);

        $archive = $this->archived($media);
        $this->assertSame('General Kenobi', json_decode($archive, true)['transcript']['segments'][2]['text'] ?? null, 'the result JSON is archived');
        $this->assertStringNotContainsString('sig=abc', $archive, 'signed URLs are removed');
        $this->assertStringNotContainsString('USEDQUOTAX', $archive, 'account usage is removed');

        Queue::assertPushed(VideoTranscriberArchiveJob::class);
        $this->assertLessThanOrEqual(2, count($this->openApiRequests()), 'at most 2 requests per FetchJob');
        $this->assertSame([], $this->legacyRequests());
    }

    public function testS03bPartialSucceededWithSegmentsCountsAsSuccess(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeSucceeded($this->resultBody($this->segments(), 'en', 138.6, 'partial_succeeded'), 'partial_succeeded');
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->refresh()->status);
        $this->assertSame('Hello there General Kenobi', $this->caption($media)?->text);
        Queue::assertPushed(VideoTranscriberArchiveJob::class);
    }

    /** SR-4.6：duration 只在 0–24 小時內採用，否則保留原值。 */
    public function testADurationOutsideZeroToTwentyFourHoursIsIgnored(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeSucceeded($this->resultBody($this->segments(), 'en', 86400 * 3));
        $media = $this->transcribingMedia('openapi', duration: 42);

        $this->fetch($media);

        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->refresh()->status);
        $this->assertSame(42, $media->duration);
    }

    #[DataProvider('locales')]
    public function testS23OpenApiUsesTheSameLanguageReconciliation(?string $language, ?string $youtube, string $text, string $locale): void
    {
        $this->useOpenApi();
        $this->youtubeSays($youtube);
        $this->fakeSucceeded($this->resultBody([$this->segment(0, 0, 2, $text)], $language));
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        $this->assertSame($locale, $this->caption($media)?->locale);
        $this->assertSame($locale, $media->refresh()->language);
    }

    // ── S23 locale ─────────────────────────────────────

    /** @return array<string, array{0: ?string, 1: ?string, 2: string, 3: string}> */
    public static function locales(): array
    {
        return [
            'zh + zh-TW'             => ['zh', 'zh-TW', '大家好，歡迎收看', 'zh-TW'],
            'zh + none, Simplified'  => ['zh', null, '这个视频说的是简体中文', 'zh-CN'],
            'zh + none, undecidable' => ['zh', null, '一二三', 'zh-TW'],
            'ja + zh-TW'             => ['ja', 'zh-TW', 'こんにちは', 'ja'],
            'none + ko'              => [null, 'ko', '안녕하세요', 'ko'],
            'none + none'            => [null, null, 'hello', 'en'],
        ];
    }

    // ── S04 兩個 provider 結果相同 ──────────────────────

    public function testS04BothProvidersWriteIdenticalCaptions(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $legacy = $this->transcribingMedia('legacy', 'audio-legacy');
        $openApi = $this->transcribingMedia('openapi');
        $this->fakeVideoTranscriber(
            [
                self::STATUS_PATH => $this->task('succeeded'),
                self::RESULT_PATH => $this->ok($this->resultBody($this->segments(), 'en')),
            ],
            [
                '*videotranscriber.ai/api/v1/transcriptions?*' => $this->ok([
                    'code' => 100000,
                    'data' => [
                        'status'   => 'success',
                        'versions' => [
                            'ai_enhanced' => ['status' => 'ready', 'subtitles' => [
                                ['start' => 0.0, 'end' => 2.5, 'text' => ' Hello there '],
                                ['start' => 2.5, 'end' => 3.0, 'text' => '   '],
                                ['start' => 3.0, 'end' => 5.25, 'text' => 'General Kenobi'],
                            ]],
                            'original' => ['status' => 'ready', 'subtitle_url' => 'https://cdn.example.test/original.json', 'subtitles' => []],
                        ],
                    ],
                ]),
                'https://cdn.example.test/original.json' => $this->ok(['detected_language' => 'en']),
            ]
        );

        $this->fetch($legacy);
        $this->fetch($openApi);

        $this->assertAskedOpenApi('GET', '/transcriptions/' . self::TASK_ID);
        $a = $this->caption($legacy);
        $b = $this->caption($openApi);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertEquals($a->segments, $b->segments);
        $this->assertSame($a->text, $b->text);
        $this->assertSame($a->locale, $b->locale);
    }

    // ── S05、S05b、S06 跟著送出時的 provider ─────────────

    public function testS05AnOpenApiSubmissionIsFetchedFromOpenApiAfterSwitchingToLegacy(): void
    {
        $this->useOpenApi();
        $this->useLegacy();
        $this->youtubeSays(null);
        $this->fakeSucceeded($this->resultBody($this->segments()));
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        Http::assertSent(fn (Request $request) => $request->url() === self::OPENAPI_URL . '/transcriptions/' . self::TASK_ID);
        $this->assertSame([], $this->legacyRequests());
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->refresh()->status);
    }

    private function fakeLegacyReady(): void
    {
        $this->fakeVideoTranscriber(others: [
            '*videotranscriber.ai/api/v1/transcriptions?*' => $this->ok([
                'code' => 100000,
                'data' => ['status' => 'success', 'versions' => [
                    'original' => ['status' => 'ready', 'subtitles' => [['start' => '00:00:00', 'end' => '00:00:02', 'text' => 'hello']]],
                ]],
            ]),
        ]);
    }

    public function testS05bALegacySubmissionIsFetchedFromLegacyAfterSwitchingToOpenApi(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeLegacyReady();
        $media = $this->transcribingMedia('legacy', 'audio-1');

        $this->fetch($media);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/transcriptions?') && str_contains($request->url(), 'audio-1'));
        $this->assertSame([], $this->openApiRequests());
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->refresh()->status);
    }

    public function testS06ARecordWithoutAProviderIsLegacy(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeLegacyReady();
        $media = $this->transcribingMedia(null, 'audio-1');

        $this->fetch($media);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/transcriptions?'));
        $this->assertSame([], $this->openApiRequests());
        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->refresh()->status);
    }

    private function fakePending(string $case): void
    {
        $this->fakeVideoTranscriber($case === 'result_not_ready'
            ? [self::STATUS_PATH => $this->task('succeeded'), self::RESULT_PATH => $this->apiError('result_not_ready', 409, true)]
            : [self::STATUS_PATH => $this->task($case)]);
    }

    #[DataProvider('pending')]
    public function testS07APendingTaskIsCheckedAgainInSixtySeconds(string $case): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakePending($case);
        $media = $this->transcribingMedia('openapi');

        $job = $this->fetch($media);

        $this->assertStillWaiting($job, $media, 60, $case);
        $this->assertNull($this->caption($media));
    }

    #[DataProvider('pending')]
    public function testS07StillPendingAfterSixtyAttemptsFails(string $case): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakePending($case);
        $media = $this->transcribingMedia('openapi');

        $job = $this->fetch($media, 60);

        $this->assertAskedOpenApi('GET', '/transcriptions/' . self::TASK_ID);
        $this->assertFalse($job->job->isReleased());
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
    }

    // ── S07 還沒好 ─────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function pending(): array
    {
        return [
            'queued'               => ['queued'],
            'processing'           => ['processing'],
            '409 result_not_ready' => ['result_not_ready'],
        ];
    }

    #[DataProvider('fetchBusyErrors')]
    public function testS08FetchRetriesTransientErrorsAfterSixtySeconds(string $case): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeVideoTranscriber([self::STATUS_PATH => match ($case) {
            'rate_limit' => $this->apiError('rate_limit_exceeded', 429, true),
            '500'        => $this->apiError('internal_error', 500, true),
            '502'        => $this->ok('<html>Bad Gateway</html>', 502),
            'connection' => $this->connectionFailure(),
        }]);
        $media = $this->transcribingMedia('openapi');

        $job = $this->fetch($media);

        $this->assertStillWaiting($job, $media, 60, $case);
    }

    // ── S08、S09 的 FetchJob 列 ──────────────────────────

    /** @return array<string, array{0: string}> */
    public static function fetchBusyErrors(): array
    {
        return [
            '429 rate_limit_exceeded' => ['rate_limit'],
            '500 internal_error'      => ['500'],
            '502 non-JSON body'       => ['502'],
            'connection failure'      => ['connection'],
        ];
    }

    public function testS09FetchRetriesAnInvalidKeyAfterFiveMinutesThenFailsAtSixty(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeVideoTranscriber([self::STATUS_PATH => $this->apiError('invalid_api_key', 401)]);
        $media = $this->transcribingMedia('openapi');

        $this->assertStillWaiting($this->fetch($media), $media, 300);

        $last = $this->fetch($media, 60);
        $this->assertFalse($last->job->isReleased());
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
    }

    #[DataProvider('fetchFailures')]
    public function testS11FetchFailuresMarkTheMediaFailed(string $case): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        if (str_starts_with($case, 'failed:')) {
            [, $code, $retryable] = explode(':', $case);
            $routes = [self::STATUS_PATH => $this->task('failed', ['code' => $code, 'message' => 'EXTERNALMESSAGEX', 'retryable' => $retryable === 'true'])];
        } else {
            $routes = match ($case) {
                'cancelled'         => [self::STATUS_PATH => $this->task('cancelled')],
                'request_not_found' => [self::STATUS_PATH => $this->apiError('request_not_found', 404)],
                'result_expired'    => [self::STATUS_PATH => $this->task('succeeded'), self::RESULT_PATH => $this->apiError('result_expired', 410)],
                'blank'             => [self::STATUS_PATH => $this->task('succeeded'), self::RESULT_PATH => $this->ok($this->resultBody([$this->segment(0, 0, 1, ' '), $this->segment(1, 1, 2, '')]))],
                'unknown'           => [self::STATUS_PATH => $this->task('paused_for_review')],
            };
        }
        $this->fakeVideoTranscriber($routes);
        $media = $this->transcribingMedia('openapi');

        $job = $this->fetch($media);

        $this->assertAskedOpenApi('GET', '/transcriptions/' . self::TASK_ID);
        $this->assertSame([], $this->legacyRequests(), $case);
        $this->assertFalse($job->job->isReleased(), $case);
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status, $case);
        $this->assertNull($this->caption($media), $case);
        Queue::assertNotPushed(VideoTranscriberArchiveJob::class);
    }

    // ── S11 的 FetchJob 列 ───────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function fetchFailures(): array
    {
        return [
            'failed, retryable false'           => ['failed:transcription_failed:false'],
            'failed, provider_unavailable'      => ['failed:provider_unavailable:true'],
            'failed, task_timeout'              => ['failed:task_timeout:true'],
            'failed, no_speech_detected'        => ['failed:no_speech_detected:false'],
            'cancelled'                         => ['cancelled'],
            '404 request_not_found'             => ['request_not_found'],
            '410 result_expired'                => ['result_expired'],
            'succeeded without usable segments' => ['blank'],
            'unknown task status'               => ['unknown'],
        ];
    }

    // ── S14 歸檔 ───────────────────────────────────────

    public function testS14ArchivingAnOpenApiVideoDownloadsNothing(): void
    {
        $this->useOpenApi();
        $this->recordLogs();
        $this->fakeVideoTranscriber(others: ['*' => $this->ok('file')]);
        $media = $this->transcribingMedia('openapi');
        $media->fill(['status' => Media::STATUS_TRANSCRIBED])->save();
        $result = $this->resultBody($this->segments());
        unset($result['files']);
        Storage::disk('s3')->put(sprintf('videotranscriber.ai/%s/transcribe.json', $media->id), (string) json_encode($result));

        $job = $this->runJob(new VideoTranscriberArchiveJob($media));

        Http::assertNothingSent();
        $this->assertSame(
            [sprintf('videotranscriber.ai/%s/transcribe.json', $media->id)],
            Storage::disk('s3')->allFiles(sprintf('videotranscriber.ai/%s', $media->id)),
            'no <version>.<kind>.json is added'
        );
        $this->assertFalse($job->job->isReleased());
        $this->assertFalse($job->job->hasFailed());
        $this->assertSame([], $this->logsAt('error'));
        $this->assertSame([], $this->logsAt('warning'), 'an openapi video is not "missing" its assets');
    }

    // ── 資安 S18、S19、S21 ─────────────────────────────

    public function testS18MalformedSegmentsAndAnUnknownLanguageAreIgnored(): void
    {
        $this->useOpenApi();
        $this->youtubeSays('ko');
        $this->fakeSucceeded($this->resultBody([
            $this->segment(0, 'NaN', 1, 'bad start'),
            $this->segment(1, 5, 3, 'end before start'),
            $this->segment(2, -1, 2, 'negative start'),
            $this->segment(3, 1, 2, "control\u{0000} char\u{0007}"),
            $this->segment(4, 2, 4, '좋은 아침'),
        ], 'xx-<script>'));
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        $caption = $this->caption($media);
        $this->assertNotNull($caption);
        $texts = array_column($caption->segments, 'text');
        $this->assertContains('좋은 아침', $texts);
        foreach (['bad start', 'end before start', 'negative start'] as $dropped) {
            $this->assertNotContains($dropped, $texts);
        }
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $caption->text . implode('', $texts));
        $this->assertSame('ko', $caption->locale);
        $this->assertSame('ko', $media->refresh()->language);
    }

    public function testS19SignedUrlsAreNotKeptOnS3OrInTheDb(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeSucceeded($this->resultBody($this->segments()));
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        $this->assertSame(Media::STATUS_TRANSCRIBED, $media->refresh()->status);
        $this->assertStringNotContainsString('sig=abc', $this->archived($media));
        $row = (string) json_encode(VideoTranscription::query()->where('media_id', $media->id)->first()?->toArray(), JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('sig=abc', $row);
    }

    public function testS19AFailedResultKeepsNoSignedUrlEither(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeSucceeded($this->resultBody([$this->segment(0, 0, 1, ' ')]));
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        $this->assertAskedOpenApi('GET', '/transcriptions/' . self::TASK_ID . '/result');
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
        $row = (string) json_encode(VideoTranscription::query()->where('media_id', $media->id)->first()?->toArray(), JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('sig=abc', $row . $this->archived($media));
    }

    public function testS21ARedirectIsNotFollowed(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeVideoTranscriber(
            [self::STATUS_PATH => $this->ok('', 302, ['Location' => 'https://other.example.test/'])],
            ['https://other.example.test*' => $this->task('succeeded')]
        );
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        $this->assertAskedOpenApi('GET', '/transcriptions/' . self::TASK_ID);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'other.example.test'));
        $this->assertNotSame(Media::STATUS_TRANSCRIBED, $media->refresh()->status);
    }
    // ── qa 邊界（T02 r1）─────────────────────────────

    #[DataProvider('busyStatusesWithUnknownCodes')]
    public function testS08FetchRetriesTransientStatusesWithAnUndocumentedCode(int $status, string $code): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeVideoTranscriber([self::STATUS_PATH => $this->apiError($code, $status, true)]);
        $media = $this->transcribingMedia('openapi');

        $this->assertStillWaiting($this->fetch($media), $media, 60, "{$status} {$code}");
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function busyStatusesWithUnknownCodes(): array
    {
        return [
            '503 service_unavailable' => [503, 'service_unavailable'],
            '429 too_many_requests'   => [429, 'too_many_requests'],
        ];
    }

    /** SR-4.2：/result 回 200 但不是 JSON，不拋例外，標失敗。 */
    public function testANonJsonResultFailsWithoutThrowing(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->fakeVideoTranscriber([
            self::STATUS_PATH => $this->task('succeeded'),
            self::RESULT_PATH => $this->ok('not json'),
        ]);
        $media = $this->transcribingMedia('openapi');

        $job = $this->fetch($media);

        $this->assertFalse($job->job->isReleased());
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
    }

    /** SR-2.2：任務錯誤的 message 是外部自由文字，不進 DB 與 log。 */
    public function testAFailedTaskMessageIsNeitherStoredNorLogged(): void
    {
        $this->useOpenApi();
        $this->youtubeSays(null);
        $this->recordLogs();
        $this->fakeVideoTranscriber([self::STATUS_PATH => $this->task('failed', ['code' => 'transcription_failed', 'message' => 'EXTERNALMESSAGEX', 'retryable' => false])]);
        $media = $this->transcribingMedia('openapi');

        $this->fetch($media);

        $row = (string) json_encode(VideoTranscription::query()->where('media_id', $media->id)->first()?->toArray());
        $this->assertStringContainsString('transcription_failed', $row, 'error.code is kept');
        $this->assertStringNotContainsString('EXTERNALMESSAGEX', $row . $this->allLogText());
    }

    /** 送出後 OPENAPI_URL 被改壞：Fetch 也不能把 key 送去別的 host。 */
    public function testFetchWithAnUntrustedOpenApiUrlSendsNothing(): void
    {
        $this->useOpenApi(url: 'https://evil.example.test/openapi/v1');
        $this->youtubeSays(null);
        $this->fakeVideoTranscriber(others: ['*' => $this->task('succeeded')]);
        $media = $this->transcribingMedia('openapi');

        $job = $this->fetch($media);

        Http::assertNothingSent();
        $this->assertStillWaiting($job, $media, 300);
    }
}
