<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber;

use Closure;
use Throwable;
use App\Models\Config;
use Hypervel\HttpClient\Response;
use Hypervel\Support\Facades\Http;
use App\Exceptions\VideoTranscriberAuthException;

class VideoTranscriberClient
{
    /**
     * The model `summary/completions` is called with when the caller does not
     * pick one — the same value the service's own web client sends.
     */
    public const SUMMARY_MODEL = 'gpt-4.1-mini';

    /**
     * The `code` videotranscriber.ai answers with on success. Everything else
     * is an error whose meaning depends on the endpoint.
     */
    protected const CODE_SUCCESS = 100000;

    /**
     * HTTP statuses that always mean the token is no longer accepted.
     */
    protected const UNAUTHORIZED_STATUSES = [401, 403];

    /**
     * How long `summary/completions` may take. The whole summary streams back
     * within one request, and a full-length transcript measured 22–31s against
     * production — straddling the client's 30s default, which would fail
     * intermittently. Generous on purpose: the cost of waiting is far lower
     * than re-running the summary.
     */
    protected const SUMMARY_TIMEOUT_SECONDS = 300;

    protected string $endpoint = 'https://videotranscriber.ai/api/v1/transcriptions/start';

    protected string $urlInfoEndpoint = 'https://videotranscriber.ai/api/v1/transcriptions/url-info';

    protected string $transcriptionEndpoint = 'https://videotranscriber.ai/api/v1/transcriptions';

    protected string $prodConfigEndpoint = 'https://videotranscriber.ai/api/v1/prod-config';

    protected string $summaryEndpoint = 'https://videotranscriber.ai/api/v1/summary/completions';

    protected string $loginEndpoint = 'https://videotranscriber.ai/api/v1/auth/email/login';

    protected string $userInfoEndpoint = 'https://videotranscriber.ai/api/v1/userinfo';

    public function __construct(
        protected SignatureGenerator $signatureGenerator = new SignatureGenerator(),
        protected ?string $cookie = null,
        protected SummaryStreamParser $summaryStreamParser = new SummaryStreamParser(),
    ) {
    }

    /**
     * Log in with email/password and persist the returned credentials to
     * the `videotranscriber` config so later requests can use its token.
     */
    public function login(string $email, string $password): array
    {
        $response = Http::asJson()->post($this->loginEndpoint, [
            'email'    => $email,
            'password' => $password,
        ]);

        $result = $response->json();

        if (($result['code'] ?? null) === self::CODE_SUCCESS) {
            Config::setValue(Config::KEY_VIDEOTRANSCRIBER, $result['data'] ?? []);
        }

        return $result;
    }

    public function startTranscription(array $params): array
    {
        // The payload is rebuilt per attempt so `t` and its signature stay
        // fresh — a retry reusing the first attempt's timestamp may be
        // rejected as stale.
        return $this->authenticated(function () use ($params) {
            $payload = array_merge([
                'lang_code'        => '',
                'diarization'      => true,
                'ai_enhance'       => true,
                'accuracy'         => 'medium',
                'referrer_url'     => '/zh-TW/youtube-transcript-generator',
                'source'           => 'web',
                'client_lang_code' => 'en',
            ], $params, [
                't' => time(),
            ]);

            $payload['sign'] = $this->signatureGenerator->generate($payload);

            return Http::asJson()->withHeaders($this->headers())->post($this->endpoint, $payload);
        });
    }

    public function getUrlInfo(string $url, int $type = 3, string $action = 'transcribe'): array
    {
        return $this->authenticated(fn () => Http::withHeaders($this->headers())->get($this->urlInfoEndpoint, [
            'url'    => $url,
            'type'   => $type,
            'action' => $action,
        ]));
    }

    /**
     * Fetch the per-session values videotranscriber.ai issues for signing
     * subsequent requests — `t`, `nonce`, `sign`, `secret_key` and `app_id`.
     *
     * Returned as-is: the values are meant to be passed straight through to
     * whatever needs them, with no decoding step of their own.
     */
    public function getProdConfig(): array
    {
        return $this->authenticated(fn () => Http::withHeaders($this->headers())->get($this->prodConfigEndpoint));
    }

    /**
     * Run a prompt through videotranscriber.ai's completion endpoint.
     *
     * Despite the `summary/completions` path this is a general completion:
     * summarising and translating both go through it, differing only in the
     * prompt they send. Keep the name endpoint-shaped rather than task-shaped.
     *
     * Every call fetches a fresh prod-config first and passes its `data` block
     * through as the query string — the values are single-use, so they cannot
     * be cached between calls. The whole block is forwarded rather than five
     * named fields, so a field the service adds later still reaches it.
     *
     * The body is sent with `streaming: true`, matching the web client, so the
     * answer arrives as SSE chunks and is reassembled before being returned.
     */
    /**
     * @param null|array<int, mixed> $selectedTexts see completionsStream()
     */
    public function completions(string $text, ?string $model = null, ?array $selectedTexts = null): string
    {
        return $this->summaryStreamParser->parse($this->completionsStream($text, $model, $selectedTexts));
    }

    /**
     * The untouched SSE body behind completions(), for callers that need
     * to stream it onward rather than wait for the whole answer.
     *
     * `selectedTexts` is omitted from the body unless a caller passes one. The
     * web client sends `selected_texts` on every request, but summarising and
     * translating have always worked without it, so it stays opt-in rather than
     * silently changing what those two send.
     *
     * @param null|array<int, mixed> $selectedTexts
     */
    public function completionsStream(string $text, ?string $model = null, ?array $selectedTexts = null): string
    {
        $query = $this->getProdConfig()['data'] ?? [];

        $payload = [
            'text'      => $text,
            'end_flag'  => true,
            'streaming' => true,
            'model'     => $model ?? self::SUMMARY_MODEL,
        ];

        if ($selectedTexts !== null) {
            $payload['selected_texts'] = $selectedTexts;
        }

        // Deliberately no Accept override: the endpoint answers with
        // `text/event-stream`, but asking for it outright gets a 406 —
        // it only accepts the `application/json` asJson() sends.
        return $this->authenticatedResponse(fn () => Http::asJson()
            ->withHeaders($this->headers())
            ->timeout(self::SUMMARY_TIMEOUT_SECONDS)
            ->post($this->summaryEndpoint . '?' . http_build_query($query), $payload))->body();
    }

    public function getTranscription(string $recordId): array
    {
        return $this->authenticated(fn () => Http::withHeaders($this->headers())->get($this->transcriptionEndpoint, [
            'record_id' => $recordId,
        ]));
    }

    /**
     * 目前這個 token 對應到哪個帳號，`null` 代表它已經不能用了。
     *
     * 判準有三層，缺一不可：HTTP 是 2xx、業務碼是 100000、而且真的回了
     * `data.user_id`。**不能只看狀態碼**——這個服務對過期的 session 也可能回
     * 200 配一個非 100000 的業務碼（`isUnauthorized()` 就是為此存在的）。
     *
     * 這支端點刻意不包 authenticated()：它是用來「判斷 token 還能不能用」的，
     * 包進去就會在失敗時自己重新登入再重放一次，那就什麼都驗不出來了。
     *
     * @return null|array<string, mixed> `data` 區塊
     */
    public function userInfo(): ?array
    {
        try {
            $response = Http::withHeaders($this->headers())->get($this->userInfoEndpoint);
        } catch (Throwable) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $result = $response->json();

        if (($result['code'] ?? null) !== self::CODE_SUCCESS) {
            return null;
        }

        $data = $result['data'] ?? null;

        return is_array($data) && isset($data['user_id']) ? $data : null;
    }

    /**
     * 確認手上的 token 還能用，不能用就重新登入一次。
     *
     * 給「要送出一批請求之前」用：先問一次 userinfo，壞了就走跟
     * `videotranscriber:login` 同一條路（`relogin()` 讀的是同一組
     * `services.videotranscriber` 帳密，成功會把新 token 寫回 configs），
     * 然後**再驗一次**——登入回成功但 token 實際不通的情況要在這裡就攔下來，
     * 而不是留給後面每一支 job 各自撞牆。
     *
     * @return null|array<string, mixed> 可用時回使用者資料，否則 null
     */
    public function ensureAuthenticated(): ?array
    {
        if ($user = $this->userInfo()) {
            return $user;
        }

        if (!$this->relogin()) {
            return null;
        }

        // relogin() 換了 configs 裡的 token，這個實例若帶著建構時傳入的 cookie
        // 就不會跟著更新——清掉它，下一次 headers() 才會去讀新的。
        $this->cookie = null;

        return $this->userInfo();
    }

    /**
     * Run a request and, if the token turns out to be expired, log in again
     * and replay it once. The callback must build the whole request itself so
     * the replay picks up the refreshed token from the DB.
     *
     * @param Closure(): Response $request
     * @throws VideoTranscriberAuthException when no working token can be obtained
     */
    protected function authenticated(Closure $request): array
    {
        return $this->authenticatedResponse($request)->json();
    }

    /**
     * The same retry-on-expired-token behaviour as authenticated(), but handing
     * back the response itself. Needed by endpoints whose body is not JSON —
     * `summary/completions` streams, so decoding it here would lose it.
     *
     * @param Closure(): Response $request
     * @throws VideoTranscriberAuthException when no working token can be obtained
     */
    protected function authenticatedResponse(Closure $request): Response
    {
        $tokenBefore = $this->tokenCookie();
        $response = $request();

        // An explicitly injected cookie is the caller's to manage: re-logging
        // in would not change what headers() sends, so replaying is pointless.
        if ($this->cookie !== null || !$this->isUnauthorized($response)) {
            return $response;
        }

        // Another worker may have refreshed the token while this request was
        // in flight; replaying with it is cheaper than a second login, and
        // avoids a stampede of logins invalidating each other.
        if ($this->tokenCookie() === $tokenBefore && !$this->relogin()) {
            throw new VideoTranscriberAuthException('Unable to refresh the videotranscriber.ai access token.');
        }

        return $request();
    }

    /**
     * Whether the response means "you are not authenticated".
     *
     * videotranscriber.ai mostly answers 200 with a business `code`, so the
     * codes that stand for an expired session are configurable and can be
     * added once observed in production, without touching this class.
     *
     * The body is only inspected when it actually decodes to an array:
     * `summary/completions` streams, and Response::json() throws a TypeError
     * rather than returning null when the body is not a JSON object.
     */
    protected function isUnauthorized(Response $response): bool
    {
        if (in_array($response->status(), self::UNAUTHORIZED_STATUSES, true)) {
            return true;
        }

        if (!is_array(json_decode($response->body(), true))) {
            return false;
        }

        $code = $response->json('code');

        if (!is_numeric($code)) {
            return false;
        }

        $unauthorizedCodes = array_map(
            'intval',
            (array) config('services.videotranscriber.unauthorized_codes', [])
        );

        return in_array((int) $code, $unauthorizedCodes, true);
    }

    /**
     * Log in again with the configured credentials, storing the new token.
     * Returns false when the credentials are missing or rejected, so callers
     * can back off rather than hammering the login endpoint.
     */
    protected function relogin(): bool
    {
        $email = config('services.videotranscriber.email');
        $password = config('services.videotranscriber.password');

        if (empty($email) || empty($password)) {
            return false;
        }

        try {
            $result = $this->login((string) $email, (string) $password);
        } catch (Throwable) {
            return false;
        }

        return ($result['code'] ?? null) === self::CODE_SUCCESS;
    }

    protected function headers(): array
    {
        $cookie = $this->cookie ?? $this->tokenCookie();

        return $cookie === null ? [] : ['Cookie' => $cookie];
    }

    /**
     * Build the `nc_token` cookie from the access token stored by login().
     */
    protected function tokenCookie(): ?string
    {
        $accessToken = Config::getValue(Config::KEY_VIDEOTRANSCRIBER)['access_token'] ?? null;

        return $accessToken === null ? null : 'nc_token=' . $accessToken;
    }
}
