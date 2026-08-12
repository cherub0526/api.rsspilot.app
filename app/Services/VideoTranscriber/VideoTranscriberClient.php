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
     * The `code` videotranscriber.ai answers with on success. Everything else
     * is an error whose meaning depends on the endpoint.
     */
    protected const CODE_SUCCESS = 100000;

    /**
     * HTTP statuses that always mean the token is no longer accepted.
     */
    protected const UNAUTHORIZED_STATUSES = [401, 403];

    protected string $endpoint = 'https://videotranscriber.ai/api/v1/transcriptions/start';

    protected string $urlInfoEndpoint = 'https://videotranscriber.ai/api/v1/transcriptions/url-info';

    protected string $transcriptionEndpoint = 'https://videotranscriber.ai/api/v1/transcriptions';

    protected string $prodConfigEndpoint = 'https://videotranscriber.ai/api/v1/prod-config';

    protected string $loginEndpoint = 'https://videotranscriber.ai/api/v1/auth/email/login';

    public function __construct(
        protected SignatureGenerator $signatureGenerator = new SignatureGenerator(),
        protected ?string $cookie = null,
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

    public function getTranscription(string $recordId): array
    {
        return $this->authenticated(fn () => Http::withHeaders($this->headers())->get($this->transcriptionEndpoint, [
            'record_id' => $recordId,
        ]));
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
        $tokenBefore = $this->tokenCookie();
        $response = $request();

        // An explicitly injected cookie is the caller's to manage: re-logging
        // in would not change what headers() sends, so replaying is pointless.
        if ($this->cookie !== null || !$this->isUnauthorized($response)) {
            return $response->json();
        }

        // Another worker may have refreshed the token while this request was
        // in flight; replaying with it is cheaper than a second login, and
        // avoids a stampede of logins invalidating each other.
        if ($this->tokenCookie() === $tokenBefore && !$this->relogin()) {
            throw new VideoTranscriberAuthException('Unable to refresh the videotranscriber.ai access token.');
        }

        return $request()->json();
    }

    /**
     * Whether the response means "you are not authenticated".
     *
     * videotranscriber.ai mostly answers 200 with a business `code`, so the
     * codes that stand for an expired session are configurable and can be
     * added once observed in production, without touching this class.
     */
    protected function isUnauthorized(Response $response): bool
    {
        if (in_array($response->status(), self::UNAUTHORIZED_STATUSES, true)) {
            return true;
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
