<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber;

use Throwable;
use App\Utils\Const\ISO6391;
use Hypervel\HttpClient\Response;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Http;
use Hypervel\HttpClient\PendingRequest;
use Hypervel\HttpClient\ConnectionException;

/**
 * videotranscriber.ai 官方 OpenAPI（https://videotranscriber.ai/code-docs）。
 *
 * 只負責對外請求與「不信任回應」的驗證，把結果轉成 StartResult／PollResult；
 * 寫 DB、release、標失敗都由 job 決定。legacy 內部 API 仍由 job 直接呼叫
 * VideoTranscriberClient，兩者以 `start_transcription.provider` 區分。
 *
 * 資安需求見 reports/videotranscriber-provider-switch/T02-security-req-r1.md（SR-1～SR-5）：
 * key 只放 Bearer header；log 與 DB 只留 HTTP status、error.code、request_id、例外類別；
 * 不跟隨轉址；task id、segments、language、duration 都先驗證再用。
 */
class OpenApiTranscriptionProvider
{
    public const NAME = 'openapi';

    public const LEGACY = 'legacy';

    /** 官方任務 id 是 `req_` + 32 位 hex；這裡只擋會改變 URL 路徑的字元。 */
    public const TASK_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    public const MAX_RESULT_BYTES = 20 * 1024 * 1024;

    public const MAX_SEGMENTS = 20_000;

    public const MAX_TEXT_BYTES = 2 * 1024 * 1024;

    /** 24 小時。超過就不是一支正常的影片，長度不採用。 */
    public const MAX_DURATION_SECONDS = 86_400;

    private const HOST = 'videotranscriber.ai';

    private const TIMEOUT_SECONDS = 30;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    private const BUSY_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'internal_error', 'provider_unavailable'];

    private const AUTH_CODES = ['invalid_api_key', 'api_key_revoked', 'api_account_disabled'];

    /** 代表我方程式或帳號出了問題，標失敗之外要有人去看。 */
    private const ALERT_CODES = ['insufficient_quota', 'invalid_request', 'invalid_idempotency_key', 'idempotency_conflict'];

    private const PENDING_STATUSES = ['queued', 'processing'];

    private const DONE_STATUSES = ['succeeded', 'partial_succeeded'];

    /** /result 裡要保存的欄位。`files` 只有 1 小時的簽名網址，不保存（SR-2.3）。 */
    private const ARCHIVE_FIELDS = ['id', 'status', 'result_expires_at', 'transcript', 'chapters', 'translation'];

    /** 建立任務回應裡要保存的欄位。 */
    private const CREATED_FIELDS = ['id', 'object', 'status', 'created_at', 'transcription_model'];

    /** `services.videotranscriber.provider` 的值；沒設定或空字串時是 legacy，不認得的值原樣回傳由呼叫端擋下。 */
    public static function configured(): string
    {
        $provider = (string) config('services.videotranscriber.provider', self::LEGACY);

        // 空字串（Railway 上留了空變數）視同沒設定，不讓轉錄整個停擺
        return $provider === '' ? self::LEGACY : $provider;
    }

    public static function isKnown(string $provider): bool
    {
        return in_array($provider, [self::LEGACY, self::NAME], true);
    }

    /**
     * 設定不完整或不可信時回傳原因（不含任何設定值），可以送出時回 null（SR-1.3、SR-3.1）。
     */
    public function configProblem(): ?string
    {
        if ((string) config('services.videotranscriber.api_key', '') === '') {
            return 'missing_api_key';
        }

        $url = parse_url($this->baseUrl());
        if (!is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || strtolower((string) ($url['host'] ?? '')) !== self::HOST
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['port'])
        ) {
            return 'untrusted_openapi_url';
        }

        return null;
    }

    /**
     * POST /transcriptions。呼叫前必須先確認 configProblem() 為 null。
     */
    public function start(string $sourceUrl, string $idempotencyKey): StartResult
    {
        try {
            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($this->baseUrl() . '/transcriptions', [
                    'source_url'          => $sourceUrl,
                    'transcription_model' => 'basic',
                ]);
        } catch (Throwable $e) {
            return new StartResult(...$this->classifyException($e));
        }

        if (!$response->successful()) {
            [$outcome, $record, $alert] = $this->classifyError($response);

            return new StartResult($outcome, $record, $alert);
        }

        $body = $this->json($response);
        $id = $body['id'] ?? null;
        if (!is_string($id) || preg_match(self::TASK_ID_PATTERN, $id) !== 1) {
            return new StartResult(Outcome::Failed, ['error' => ['status' => $response->status(), 'code' => 'invalid_task_id']], true);
        }

        return new StartResult(Outcome::Ok, [
            'code' => 100000,
            'data' => ['audio_id' => $id],
            'raw'  => array_intersect_key($body, array_flip(self::CREATED_FIELDS)),
        ]);
    }

    /**
     * GET /transcriptions/{id}，完成時再 GET /result。單次最多 2 個請求。
     */
    public function poll(string $taskId): PollResult
    {
        // 拼進路徑前再驗一次：DB 裡的值也不信任（SR-4.1）
        if (preg_match(self::TASK_ID_PATTERN, $taskId) !== 1) {
            return new PollResult(Outcome::Failed, failure: ['error' => ['code' => 'invalid_task_id']], alert: true);
        }

        $path = $this->baseUrl() . '/transcriptions/' . rawurlencode($taskId);

        try {
            $response = $this->request()->get($path);
        } catch (Throwable $e) {
            [$outcome, $record, $alert] = $this->classifyException($e);

            return new PollResult($outcome, failure: $record, alert: $alert);
        }

        if (!$response->successful()) {
            [$outcome, $record, $alert] = $this->classifyError($response);

            return new PollResult($outcome, failure: $record, alert: $alert);
        }

        $task = $this->json($response);
        $status = $task['status'] ?? null;

        if (in_array($status, self::PENDING_STATUSES, true)) {
            return new PollResult(Outcome::Pending);
        }

        if (!in_array($status, self::DONE_STATUSES, true)) {
            // failed（不論 retryable）、cancelled、不認得的狀態一律失敗
            $error = is_array($task['error'] ?? null) ? $task['error'] : [];

            return new PollResult(Outcome::Failed, failure: [
                'status' => is_string($status) ? mb_substr($status, 0, 64) : null,
                'error'  => [
                    'code'      => $this->string($error['code'] ?? null),
                    'retryable' => is_bool($error['retryable'] ?? null) ? $error['retryable'] : null,
                ],
            ]);
        }

        return $this->result($path . '/result');
    }

    private function result(string $url): PollResult
    {
        try {
            $response = $this->request()->get($url);
        } catch (Throwable $e) {
            [$outcome, $record, $alert] = $this->classifyException($e);

            return new PollResult($outcome, failure: $record, alert: $alert);
        }

        if (!$response->successful()) {
            [$outcome, $record, $alert] = $this->classifyError($response);

            return new PollResult($outcome, failure: $record, alert: $alert);
        }

        // 不 decode 異常大的回應，也不寫進 S3（SR-4.7）
        if (strlen($response->body()) > self::MAX_RESULT_BYTES) {
            return new PollResult(Outcome::Failed, failure: ['error' => ['status' => $response->status(), 'code' => 'result_too_large']], alert: true);
        }

        $body = $this->json($response);
        $archive = array_intersect_key($body, array_flip(self::ARCHIVE_FIELDS));
        $transcript = is_array($body['transcript'] ?? null) ? $body['transcript'] : [];
        $segments = $this->segments(is_array($transcript['segments'] ?? null) ? $transcript['segments'] : []);

        if ($segments === []) {
            return new PollResult(Outcome::Failed, archive: $archive, failure: ['status' => $this->string($body['status'] ?? null), 'error' => ['code' => 'no_usable_segments']]);
        }

        return new PollResult(
            Outcome::Ok,
            segments: $segments,
            language: $this->language($transcript['language'] ?? null),
            duration: $this->duration($transcript['duration_seconds'] ?? null),
            archive: $archive,
        );
    }

    /**
     * 合法的段落：start／end 是有限數值、≥ 0、end ≥ start；text 去掉控制字元後非空白。
     * `id`、`speaker` 不保留。數量與總長度超過上限時截斷並記 warning（不記內容）。
     *
     * @param array<mixed> $raw
     * @return list<array{start: float, end: float, text: string}>
     */
    private function segments(array $raw): array
    {
        $segments = [];
        $bytes = 0;
        $truncated = false;

        foreach ($raw as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            if (!is_int($start) && !is_float($start) || !is_int($end) && !is_float($end)) {
                continue;
            }
            $start = (float) $start;
            $end = (float) $end;
            if (!is_finite($start) || !is_finite($end) || $start < 0 || $end < $start) {
                continue;
            }

            $text = $segment['text'] ?? null;
            if (!is_string($text)) {
                continue;
            }
            $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
            if ($text === '') {
                continue;
            }

            if (count($segments) >= self::MAX_SEGMENTS || $bytes + strlen($text) > self::MAX_TEXT_BYTES) {
                $truncated = true;
                break;
            }

            $bytes += strlen($text);
            $segments[] = ['start' => $start, 'end' => $end, 'text' => $text];
        }

        if ($truncated) {
            Log::warning('videotranscriber openapi transcript exceeded the caption limits and was truncated.', [
                'provider' => self::NAME,
                'segments' => count($segments),
            ]);
        }

        return $segments;
    }

    /** normalize 後必須在 ISO6391 白名單內，否則當成未知，交給 YouTube 協調（SR-4.5）。 */
    private function language(mixed $language): string
    {
        if (!is_string($language) || trim($language) === '') {
            return '';
        }

        $normalized = ISO6391::normalize($language);

        return in_array($normalized, ISO6391::LANGUAGES, true) ? $normalized : '';
    }

    /** 只採用 0–24 小時內的長度（SR-4.6）。0 代表沒有資訊，不覆寫。 */
    private function duration(mixed $seconds): ?int
    {
        if (!is_int($seconds) && !is_float($seconds)) {
            return null;
        }

        $seconds = (float) $seconds;
        if (!is_finite($seconds) || $seconds <= 0 || $seconds > self::MAX_DURATION_SECONDS) {
            return null;
        }

        return (int) round($seconds);
    }

    /**
     * 非 2xx 的回應。依 error.code 分支，不看 error.message；沒有可辨識的 code 時依 HTTP status。
     *
     * @return array{0: Outcome, 1: array<string, mixed>, 2: bool}
     */
    private function classifyError(Response $response): array
    {
        $status = $response->status();
        $error = $this->json($response)['error'] ?? null;
        $error = is_array($error) ? $error : [];
        $code = $this->string($error['code'] ?? null);
        $record = ['error' => array_filter([
            'status'     => $status,
            'code'       => $code,
            'request_id' => $this->string($error['request_id'] ?? null),
        ], fn ($value) => $value !== null)];

        // 可辨識的 code 優先；其次是 HTTP status，暫時性的 429／5xx 即使帶著官方表上沒有的
        // code 也要重試（錯誤碼表只是範例，閘道或維護時回的 code 不保證在裡面）
        $outcome = match (true) {
            $code === 'result_not_ready'            => Outcome::Pending,
            in_array($code, self::BUSY_CODES, true) => Outcome::Busy,
            in_array($code, self::AUTH_CODES, true) => Outcome::Unauthorized,
            $status === 429 || $status >= 500       => Outcome::Busy,
            $code !== null                          => Outcome::Failed,
            $status === 401 || $status === 403      => Outcome::Unauthorized,
            default                                 => Outcome::Failed,
        };

        // 3xx 不跟隨（SR-3.2），也不是官方會回的狀態：當成失敗並記 log
        $alert = in_array($code, self::ALERT_CODES, true) || ($status >= 300 && $status < 400);

        return [$outcome, $record, $alert];
    }

    /**
     * 沒有拿到回應。例外訊息帶完整網址，只保存類別名稱（SR-2.1）。
     *
     * @return array{0: Outcome, 1: array<string, mixed>, 2: bool}
     */
    private function classifyException(Throwable $e): array
    {
        $record = ['error' => ['exception' => $e::class]];

        return $e instanceof ConnectionException
            ? [Outcome::Busy, $record, false]
            : [Outcome::Failed, $record, true];
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('services.videotranscriber.api_key'))
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.videotranscriber.openapi_url', ''), '/');
    }

    /** @return array<string, mixed> 不是 JSON object 時回 []（SR-4.2） */
    private function json(Response $response): array
    {
        try {
            $body = json_decode($response->body(), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($body) ? $body : [];
    }

    /** 外部字串只保留短的識別值，避免自由文字流進 DB。 */
    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 128) : null;
    }
}
