<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Media;

use Tests\TestCase;
use App\Models\Media;
use Hypervel\HttpClient\Request;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Http;
use App\Jobs\Media\VideoTranscriberStartJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesVideoTranscriberOpenApi;

/**
 * videotranscriber-provider-switch 驗收：StartJob（S01、S02、S08–S12、S16、S17、S20）。
 *
 * 介面約定（qa 依 plan 訂的，backend 可以提議改）：
 * - config `services.videotranscriber.{provider,api_key,openapi_url}`
 * - `start_transcription` 形狀 `{provider, code: 100000, data: {audio_id}, ...}`（plan〈每筆 media 記住 provider〉）
 * - POST body 帶 `source_url`（YouTube 網址）與 `transcription_model: basic`
 * - handle() 經容器呼叫，參數怎麼注入不限
 *
 * @internal
 * @coversNothing
 */
class VideoTranscriberOpenApiStartJobTest extends TestCase
{
    use RefreshDatabase;
    use FakesVideoTranscriberOpenApi;

    private function start(Media $media, int $attempts = 1): VideoTranscriberStartJob
    {
        /** @var VideoTranscriberStartJob */
        return $this->runJob(new VideoTranscriberStartJob($media), $attempts);
    }

    private function startTranscription(Media $media): array
    {
        return (array) VideoTranscription::query()->where('media_id', $media->id)->value('start_transcription');
    }

    private function row(Media $media): string
    {
        return (string) json_encode(VideoTranscription::query()->where('media_id', $media->id)->first()?->toArray(), JSON_UNESCAPED_SLASHES);
    }

    // ── 選擇 provider ─────────────────────────────────

    public function testS01WithoutAProviderSettingItStaysOnLegacy(): void
    {
        $this->unsetProvider();
        $this->fakeVideoTranscriber(others: [
            '*videotranscriber.ai/api/v1/transcriptions/url-info*' => $this->ok(['code' => 100000, 'data' => ['type' => 3, 'title' => 'T', 'audio_time' => 139]]),
            '*videotranscriber.ai/api/v1/transcriptions/start*'    => $this->ok(['code' => 100000, 'data' => ['event_id' => 'e-1', 'audio_id' => 'audio-1']]),
        ]);
        $media = $this->createdMedia();

        $this->start($media);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/transcriptions/url-info'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/v1/transcriptions/start'));
        $this->assertSame([], $this->openApiRequests());
        $this->assertSame('legacy', $this->startTranscription($media)['provider'] ?? null);
        $this->assertSame('audio-1', $this->startTranscription($media)['data']['audio_id'] ?? null);
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->refresh()->status);
    }

    public function testS02OpenApiSubmitsThroughTheOfficialApi(): void
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $media = $this->createdMedia(duration: 77);

        $this->start($media);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::OPENAPI_URL . '/transcriptions'
            && $request->header('Authorization') === ['Bearer ' . self::API_KEY]
            && ($request->header('Idempotency-Key')[0] ?? '') !== ''
            && $request['transcription_model'] === 'basic'
            && $request['source_url'] === 'https://www.youtube.com/watch?v=' . self::VIDEO_ID);
        $this->assertSame([], $this->legacyRequests());

        $start = $this->startTranscription($media);
        $this->assertSame('openapi', $start['provider'] ?? null);
        $this->assertSame(self::TASK_ID, $start['data']['audio_id'] ?? null);

        $media->refresh();
        $this->assertSame(Media::STATUS_TRANSCRIBING, $media->status);
        $this->assertSame(77, $media->duration, 'Start does not touch duration in openapi mode');
    }

    #[DataProvider('startBusyErrors')]
    public function testS08StartRetriesTransientErrorsAfterSixtySeconds(string $case): void
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber(['POST /transcriptions' => match ($case) {
            'rate_limit'  => $this->apiError('rate_limit_exceeded', 429, true),
            'concurrency' => $this->apiError('concurrency_limit_exceeded', 429, true),
            '500'         => $this->apiError('internal_error', 500, true),
            '503'         => $this->ok('<html>Service Unavailable</html>', 503),
            'connection'  => $this->connectionFailure(),
        }]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        $this->assertTrue($job->job->isReleased(), $case);
        $this->assertSame(60, $job->job->releaseDelay, $case);
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status, $case);
    }

    // ── S08 暫時性錯誤 ─────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function startBusyErrors(): array
    {
        return [
            '429 rate_limit_exceeded'        => ['rate_limit'],
            '429 concurrency_limit_exceeded' => ['concurrency'],
            '500 internal_error'             => ['500'],
            '503 non-JSON body'              => ['503'],
            'connection failure'             => ['connection'],
        ];
    }

    #[DataProvider('startAuthErrors')]
    public function testS09StartRetriesAuthErrorsAfterFiveMinutes(string $code, int $status): void
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->apiError($code, $status)]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        $this->assertTrue($job->job->isReleased());
        $this->assertSame(300, $job->job->releaseDelay);
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status);
    }

    #[DataProvider('startAuthErrors')]
    public function testS09StartFailsOnceTheTwelveAuthAttemptsAreUsed(string $code, int $status): void
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->apiError($code, $status)]);
        $media = $this->createdMedia();

        $job = $this->start($media, 12);

        $this->assertAskedOpenApi('POST', '/transcriptions');
        $this->assertFalse($job->job->isReleased());
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
    }

    // ── S09 驗證失敗 ───────────────────────────────────

    /** @return array<string, array{0: string, 1: int}> */
    public static function startAuthErrors(): array
    {
        return [
            '401 invalid_api_key'      => ['invalid_api_key', 401],
            '401 api_key_revoked'      => ['api_key_revoked', 401],
            '403 api_account_disabled' => ['api_account_disabled', 403],
        ];
    }

    public function testS09AnEmptyApiKeySendsNothingAndRetriesAsAnAuthFailure(): void
    {
        $this->useOpenApi(apiKey: '');
        $this->recordLogs();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created()]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        Http::assertNothingSent();
        $this->assertTrue($job->job->isReleased());
        $this->assertSame(300, $job->job->releaseDelay);
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status);
        $this->assertNotEmpty($this->logsAt('error'), 'an error log for the missing key');
        $this->assertStringNotContainsString(self::OPENAPI_URL, $this->allLogText(), 'the log carries no config value');

        $last = $this->start($media, 12);
        $this->assertFalse($last->job->isReleased());
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
    }

    // ── S10、S11 無法完成 ──────────────────────────────

    public function testS10InsufficientQuotaFailsWithAnErrorLogAndNoResubmission(): void
    {
        $this->useOpenApi();
        $this->recordLogs();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->apiError('insufficient_quota', 402)]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
        $this->assertFalse($job->job->isReleased());
        $this->assertNotEmpty($this->logsAt('error'));
        $this->assertCount(1, $this->idempotencyKeys(), 'no second submission under a new key');
    }

    #[DataProvider('startFailures')]
    public function testS11StartFailuresMarkTheMediaFailed(string $code, int $status, bool $logsAnError): void
    {
        $this->useOpenApi();
        $this->recordLogs();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->apiError($code, $status)]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
        $this->assertFalse($job->job->isReleased());
        $this->assertCount(1, $this->idempotencyKeys(), 'no automatic resubmission');
        if ($logsAnError) {
            $this->assertNotEmpty($this->logsAt('error'), $code);
        }
        // SR-2.2：error.message 是外部自由文字，不進 log
        $this->assertStringNotContainsString('EXTERNALMESSAGEX', $this->allLogText());
    }

    /** @return array<string, array{0: string, 1: int, 2: bool}> */
    public static function startFailures(): array
    {
        return [
            '400 unsupported_source_url'  => ['unsupported_source_url', 400, false],
            '400 invalid_request'         => ['invalid_request', 400, true],
            '400 invalid_idempotency_key' => ['invalid_idempotency_key', 400, true],
            '409 idempotency_conflict'    => ['idempotency_conflict', 409, true],
        ];
    }

    // ── S12 Idempotency-Key ──────────────────────────

    public function testS12ARetryOfTheSameSubmissionReusesTheKeyAndAnotherVideoGetsItsOwn(): void
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber(['POST /transcriptions' => fn (Request $request) => $request['source_url'] === 'https://www.youtube.com/watch?v=' . self::VIDEO_ID
            ? Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'x', 'request_id' => 'r', 'retryable' => true, 'details' => null]], 429)
            : ($this->created('task_002'))()]);
        $media = $this->createdMedia();
        $other = $this->createdMedia('zzz999');

        $job = new VideoTranscriberStartJob($media);
        $this->runJob($job);
        $this->assertTrue($job->job->isReleased(), 'first attempt is released');
        $this->runJob($job, 2);
        $this->start($other);

        [$first, $retry, $otherKey] = $this->idempotencyKeys();
        $this->assertSame($first, $retry, 'a released retry keeps the key');
        $this->assertNotSame($first, $otherKey, 'another video has its own key');
        foreach ([$first, $otherKey] as $key) {
            $this->assertMatchesRegularExpression('/^[\x21-\x7E]{1,128}$/', (string) $key, 'visible ASCII, no spaces, 1-128 chars');
            $this->assertStringNotContainsString(self::API_KEY, (string) $key);
        }
    }

    // ── 資安 S16、S17、S20 ─────────────────────────────

    public function testS16AFailedRequestLeavesNoKeyOrBodyInTheDbOrLogs(): void
    {
        $this->useOpenApi();
        $this->recordLogs();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->ok([
            'error' => ['code' => 'internal_error', 'message' => 'secret-body-marker', 'request_id' => 'req_trace_1', 'retryable' => true, 'details' => ['secret-body-marker']],
        ], 500)]);
        $media = $this->createdMedia();

        $this->start($media);

        // 欄位名稱由 backend 決定，只要求 status 與 error.code 留得下來
        $start = (string) json_encode($this->startTranscription($media));
        $this->assertStringContainsString('internal_error', $start, 'error.code is kept');
        $this->assertStringContainsString('500', $start, 'the HTTP status is kept');
        foreach (['test-key-123', 'secret-body-marker'] as $marker) {
            $this->assertStringNotContainsString($marker, $this->row($media), "video_transcriptions: {$marker}");
            $this->assertStringNotContainsString($marker, $this->allLogText(), "logs: {$marker}");
        }

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer ' . self::API_KEY]
            && !str_contains($request->url(), self::API_KEY)
            && !str_contains($request->body(), self::API_KEY));
    }

    public function testS16AConnectionFailureLeavesNoUrlOrKeyInTheDbOrLogs(): void
    {
        $this->useOpenApi();
        $this->recordLogs();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->connectionFailure()]);
        $media = $this->createdMedia();

        $this->start($media);

        $this->assertAskedOpenApi('POST', '/transcriptions');
        // ConnectException 的訊息帶完整網址；只能存例外類別
        $this->assertStringNotContainsString('cURL error', $this->row($media) . $this->allLogText());
        $this->assertStringNotContainsString(self::API_KEY, $this->row($media) . $this->allLogText());
    }

    #[DataProvider('unsafeTaskIds')]
    public function testS17AnUnsafeTaskIdFailsAndIsNeverStored(string $id): void
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->created($id)]);
        $media = $this->createdMedia();

        $this->start($media);

        $this->assertAskedOpenApi('POST', '/transcriptions');
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
        $this->assertNotSame($id, $this->startTranscription($media)['data']['audio_id'] ?? null, 'the unsafe id is not stored as the task id');
        Http::assertNotSent(fn (Request $request) => str_contains(rawurldecode($request->url()), '../'));
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeTaskIds(): array
    {
        return [
            'dot segment' => ['../account'],
            'slash'       => ['task/../../account'],
            'query'       => ['task?x=1'],
            'too long'    => [str_repeat('a', 129)],
            'empty'       => [''],
        ];
    }

    #[DataProvider('untrustedUrls')]
    public function testS20AnUntrustedOpenApiUrlSendsNothingAndRetriesAsAnAuthFailure(string $url): void
    {
        $this->useOpenApi(url: $url);
        $this->recordLogs();
        // 什麼網址都接，好確認真的一個都沒送（不靠 preventStrayRequests 的例外）
        $this->fakeVideoTranscriber(others: ['*' => $this->created()]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        Http::assertNothingSent();
        $this->assertTrue($job->job->isReleased());
        $this->assertSame(300, $job->job->releaseDelay);
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status);
        $this->assertNotEmpty($this->logsAt('error'));
        $this->assertStringNotContainsString('evil.example.test', $this->allLogText());
        $this->assertStringNotContainsString(self::API_KEY, $this->allLogText());
    }

    /** @return array<string, array{0: string}> */
    public static function untrustedUrls(): array
    {
        return [
            'other host'       => ['http://evil.example.test/openapi/v1'],
            'https other host' => ['https://evil.example.test/openapi/v1'],
            'http official'    => ['http://videotranscriber.ai/openapi/v1'],
            'lookalike suffix' => ['https://videotranscriber.ai.evil.example.test/openapi/v1'],
            'userinfo trick'   => ['https://videotranscriber.ai@evil.example.test/openapi/v1'],
        ];
    }
    // ── qa 邊界（T02 r1）─────────────────────────────

    /** S08「HTTP 5xx」不論 body 帶什麼 code 都是暫時性的；429 同理（官方文件：429 要等 Retry-After 再試）。 */
    #[DataProvider('busyStatusesWithUnknownCodes')]
    public function testS08TransientStatusesWithAnUndocumentedCodeAreStillRetried(int $status, string $code): void
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->apiError($code, $status, true)]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        $this->assertTrue($job->job->isReleased(), "{$status} {$code}");
        $this->assertSame(60, $job->job->releaseDelay);
        $this->assertSame(Media::STATUS_CREATED, $media->refresh()->status);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function busyStatusesWithUnknownCodes(): array
    {
        return [
            '503 service_unavailable' => [503, 'service_unavailable'],
            '502 bad_gateway'         => [502, 'bad_gateway'],
            '429 too_many_requests'   => [429, 'too_many_requests'],
        ];
    }

    /** SR-4.2：202 但 body 不是 JSON，不能拋例外，當成失敗。 */
    public function testANonJsonCreatedResponseFailsWithoutThrowing(): void
    {
        $this->useOpenApi();
        $this->fakeVideoTranscriber(['POST /transcriptions' => $this->ok('<html>ok</html>', 202)]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        $this->assertFalse($job->job->isReleased());
        $this->assertSame(Media::STATUS_TRANSCRIBE_FAILED, $media->refresh()->status);
    }

    /** 已排進佇列之後設定才被改壞：job 不送任何東西，比照驗證重試。 */
    public function testAJobQueuedBeforeTheProviderWasMistypedSendsNothing(): void
    {
        config()->set('services.videotranscriber.provider', 'openAPI');
        config()->set('services.videotranscriber.api_key', self::API_KEY);
        $this->recordLogs();
        $this->fakeVideoTranscriber(others: ['*' => $this->created()]);
        $media = $this->createdMedia();

        $job = $this->start($media);

        Http::assertNothingSent();
        $this->assertTrue($job->job->isReleased());
        $this->assertSame(300, $job->job->releaseDelay);
        $this->assertNotEmpty($this->logsAt('error'));
    }
}
