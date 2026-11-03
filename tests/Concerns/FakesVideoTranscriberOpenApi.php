<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Mockery;
use App\Models\User;
use App\Models\Media;
use Hypervel\Support\Str;
use App\Services\YoutubeService;
use Hypervel\HttpClient\Request;
use Hypervel\Queue\Jobs\FakeJob;
use Google\Service\YouTube\Video;
use Hypervel\Support\Facades\Log;
use App\Models\VideoTranscription;
use Hypervel\Support\Facades\Http;
use Google\Service\YouTube\VideoSnippet;

/**
 * videotranscriber 的兩組 API 共用的 fake 與 fixture（videotranscriber-provider-switch）。
 *
 * 官方 OpenAPI 的形狀依 https://videotranscriber.ai/code-docs（2026-11-03 讀取）：
 * - HTTP 錯誤：`{"error":{"code","message","request_id","retryable","details"}}`
 * - 任務錯誤：GET /transcriptions/{id} 回 200，`status=failed`，`error:{code,message,retryable}`
 * 文件沒有 failed 任務的 JSON 範例，那一種是依欄位表組出來的。
 *
 * 每個案例自己呼叫 fakeVideoTranscriber()，不放 setUp()：stub 先註冊先贏，而漏 fake 會真的
 * 打到正式環境（docs/lore/framework/pitfalls.md）。沒有對到的請求一律被 preventStrayRequests 擋下。
 */
trait FakesVideoTranscriberOpenApi
{
    protected const OPENAPI_URL = 'https://videotranscriber.ai/openapi/v1';

    protected const API_KEY = 'test-key-123';

    protected const TASK_ID = 'task_001';

    protected const VIDEO_ID = 'abc123';

    /** 收下所有 log 呼叫。 */
    protected object $logs;

    protected function useOpenApi(string $apiKey = self::API_KEY, string $url = self::OPENAPI_URL): void
    {
        config()->set('services.videotranscriber.provider', 'openapi');
        config()->set('services.videotranscriber.api_key', $apiKey);
        config()->set('services.videotranscriber.openapi_url', $url);
    }

    protected function useLegacy(): void
    {
        config()->set('services.videotranscriber.provider', 'legacy');
    }

    /** 「未設定」：整個 key 拿掉，不是設成 null。 */
    protected function unsetProvider(): void
    {
        $config = (array) config('services.videotranscriber');
        unset($config['provider']);
        config()->set('services.videotranscriber', $config);
    }

    /**
     * @param array<string, callable> $openApi `METHOD /path`（/openapi/v1 之後的部分）→ stub，只認 videotranscriber.ai
     * @param array<string, callable> $others 完整網址的 Str::is pattern → stub（legacy 端點、字幕檔等）
     */
    protected function fakeVideoTranscriber(array $openApi = [], array $others = []): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($openApi, $others) {
            $url = $request->url();
            $host = (string) parse_url($url, PHP_URL_HOST);
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($host === 'videotranscriber.ai' && str_starts_with($path, '/openapi/v1/')) {
                $stub = $openApi[$request->method() . ' ' . substr($path, strlen('/openapi/v1'))] ?? null;

                return $stub === null ? null : $stub($request);
            }

            foreach ($others as $pattern => $stub) {
                if (Str::is($pattern, $url)) {
                    return $stub($request);
                }
            }

            return null;
        });
    }

    /** 每次呼叫都產生新的 response，同一個 stub 可以被打很多次。 */
    protected function ok(array|string $body, int $status = 200, array $headers = []): callable
    {
        return fn () => Http::response($body, $status, $headers);
    }

    /** 官方 HTTP 錯誤 envelope。message 放外部自由文字的標記，用來檢查沒有被寫進 log／DB。 */
    protected function apiError(string $code, int $status, bool $retryable = false): callable
    {
        return $this->ok([
            'error' => [
                'code'       => $code,
                'message'    => 'EXTERNALMESSAGEX',
                'request_id' => 'req_trace_1',
                'retryable'  => $retryable,
                'details'    => null,
            ],
        ], $status);
    }

    protected function connectionFailure(): callable
    {
        return fn (Request $request) => Http::failedConnection()($request);
    }

    /** POST /transcriptions 的 202。 */
    protected function created(string $id = self::TASK_ID): callable
    {
        return $this->ok([
            'id'                  => $id,
            'object'              => 'transcription',
            'status'              => 'queued',
            'created_at'          => '2026-11-03T00:00:00Z',
            'transcription_model' => 'basic',
            'poll_url'            => '/openapi/v1/transcriptions/' . $id,
            'retry_after'         => 5,
        ], 202);
    }

    /** GET /transcriptions/{id}。 */
    protected function task(string $status, ?array $error = null): callable
    {
        $done = in_array($status, ['succeeded', 'partial_succeeded'], true);

        return $this->ok(array_filter([
            'id'                  => self::TASK_ID,
            'object'              => 'transcription',
            'status'              => $status,
            'created_at'          => '2026-11-03T00:00:00Z',
            'transcription_model' => 'basic',
            'duration_seconds'    => $done ? 138.6 : null,
            'billable_minutes'    => $done ? 3 : null,
            'usage'               => ['reserved_quotas' => 3, 'used_quotas' => 'USEDQUOTAX'],
            'result_available'    => $done,
            'result_expires_at'   => $done ? '2026-12-03T00:00:00Z' : null,
            'error'               => $error,
            'result_url'          => $done ? '/openapi/v1/transcriptions/' . self::TASK_ID . '/result' : null,
            'retry_after'         => $done ? null : 5,
        ], fn ($value) => $value !== null));
    }

    /**
     * GET /transcriptions/{id}/result 的 body。
     *
     * @param list<array<string, mixed>> $segments
     */
    protected function resultBody(array $segments, ?string $language = 'en', mixed $duration = 138.6, string $status = 'succeeded'): array
    {
        return [
            'id'                => self::TASK_ID,
            'status'            => $status,
            'result_expires_at' => '2026-12-03T00:00:00Z',
            'transcript'        => [
                'language'         => $language,
                'duration_seconds' => $duration,
                'text'             => implode(' ', array_map(fn (array $s) => trim((string) $s['text']), $segments)),
                'segments'         => $segments,
                'audio_events'     => [],
            ],
            'chapters'    => null,
            'translation' => null,
            'files'       => [
                'subtitles' => [
                    'srt' => ['url' => 'https://cdn.example.test/x.srt?sig=abc', 'expires_at' => '2026-11-03T01:00:00Z'],
                    'vtt' => ['url' => 'https://cdn.example.test/x.vtt?sig=abc', 'expires_at' => '2026-11-03T01:00:00Z'],
                ],
            ],
        ];
    }

    /** 官方 segment：帶 id 與 speaker，兩者都不該寫進 captions。 */
    protected function segment(int $id, mixed $start, mixed $end, string $text): array
    {
        return ['id' => $id, 'start' => $start, 'end' => $end, 'text' => $text, 'speaker' => 'SPEAKER_00'];
    }

    /**
     * 已通過公開字幕檢查、等待送出的影片（feature 背景）。
     *
     * 預設有一位使用者收錄：transcribe-owned-only 之後，沒人收錄的影片不會送出。
     */
    protected function createdMedia(string $videoId = self::VIDEO_ID, int $duration = 0, bool $owned = true): Media
    {
        $media = Media::factory()->create([
            'status'       => Media::STATUS_CREATED,
            'duration'     => $duration,
            'video_detail' => ['yt:videoId' => $videoId, 'public_captions' => true],
        ]);

        if ($owned) {
            $media->users()->attach(User::factory()->create()->id);
        }

        return $media;
    }

    /** 已由某個 provider 送出、等待取回的影片；provider 為 null 代表沒有這個 key 的舊資料。 */
    protected function transcribingMedia(?string $provider, string $taskId = self::TASK_ID, int $duration = 0): Media
    {
        $media = Media::factory()->create([
            'status'       => Media::STATUS_TRANSCRIBING,
            'duration'     => $duration,
            'video_detail' => ['yt:videoId' => self::VIDEO_ID, 'public_captions' => true],
        ]);

        $start = ['code' => 100000, 'data' => ['audio_id' => $taskId]];
        if ($provider !== null) {
            $start = ['provider' => $provider, ...$start];
        }

        VideoTranscription::factory()->create([
            'media_id'            => $media->id,
            'url_info'            => $provider === 'openapi' ? null : ['code' => 100000],
            'start_transcription' => $start,
        ]);

        return $media;
    }

    /** 透過容器呼叫 handle()，不綁死 handle 的參數（provider 的注入方式由 backend 決定）。 */
    protected function runJob(object $job, int $attempts = 1): object
    {
        $job->job = new FakeJob();
        $job->job->attempts = $attempts;

        app()->call([$job, 'handle']);

        return $job;
    }

    /** YouTube 回指定的 defaultAudioLanguage；null 代表沒有。 */
    protected function youtubeSays(?string $defaultAudioLanguage = null): void
    {
        $video = null;
        if ($defaultAudioLanguage !== null) {
            $snippet = new VideoSnippet();
            $snippet->setDefaultAudioLanguage($defaultAudioLanguage);
            $video = new Video();
            $video->setSnippet($snippet);
        }

        $mock = Mockery::mock(YoutubeService::class);
        $mock->shouldReceive('getVideoDetails')->andReturn($video);
        $this->app->instance(YoutubeService::class, $mock);
    }

    protected function recordLogs(): void
    {
        $this->logs = new class {
            /** @var list<array{0: string, 1: array}> */
            public array $records = [];

            public function __call(string $method, array $args): mixed
            {
                $this->records[] = [$method, $args];

                return $this;
            }
        };
        Log::swap($this->logs);
    }

    /** @return list<array{0: string, 1: array}> */
    protected function logsAt(string $level): array
    {
        return array_values(array_filter($this->logs->records, fn (array $record) => $record[0] === $level));
    }

    protected function allLogText(): string
    {
        return (string) json_encode($this->logs->records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<Request> */
    protected function openApiRequests(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/openapi/v1/'))
            ->map(fn (array $pair) => $pair[0])->values()->all();
    }

    /** 路徑不含 /openapi/v1/ 的 videotranscriber.ai 請求即 legacy（feature S02）。 */
    protected function legacyRequests(): array
    {
        return Http::recorded(fn (Request $request) => parse_url($request->url(), PHP_URL_HOST) === 'videotranscriber.ai'
            && !str_contains($request->url(), '/openapi/v1/'))
            ->map(fn (array $pair) => $pair[0])->values()->all();
    }

    /** 確實走了 openapi：否則「標失敗」可能只是 legacy 撞牆的結果。 */
    protected function assertAskedOpenApi(string $method, string $path): void
    {
        Http::assertSent(fn (Request $request) => $request->method() === $method
            && $request->url() === self::OPENAPI_URL . $path);
    }

    protected function idempotencyKeys(): array
    {
        return array_values(array_map(
            fn (Request $request) => $request->header('Idempotency-Key')[0] ?? null,
            array_filter($this->openApiRequests(), fn (Request $request) => $request->method() === 'POST')
        ));
    }
}
