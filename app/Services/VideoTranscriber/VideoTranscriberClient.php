<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber;

use App\Models\Config;
use Hypervel\Support\Facades\Http;

class VideoTranscriberClient
{
    protected string $endpoint = 'https://videotranscriber.ai/api/v1/transcriptions/start';

    protected string $urlInfoEndpoint = 'https://videotranscriber.ai/api/v1/transcriptions/url-info';

    protected string $transcriptionEndpoint = 'https://videotranscriber.ai/api/v1/transcriptions';

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

        if (($result['code'] ?? null) === 100000) {
            Config::setValue(Config::KEY_VIDEOTRANSCRIBER, $result['data'] ?? []);
        }

        return $result;
    }

    public function startTranscription(array $params): array
    {
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

        $response = Http::asJson()->withHeaders($this->headers())->post($this->endpoint, $payload);

        return $response->json();
    }

    public function getUrlInfo(string $url, int $type = 3, string $action = 'transcribe'): array
    {
        $response = Http::withHeaders($this->headers())->get($this->urlInfoEndpoint, [
            'url'    => $url,
            'type'   => $type,
            'action' => $action,
        ]);

        return $response->json();
    }

    public function getTranscription(string $recordId): array
    {
        $response = Http::withHeaders($this->headers())->get($this->transcriptionEndpoint, [
            'record_id' => $recordId,
        ]);

        return $response->json();
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
