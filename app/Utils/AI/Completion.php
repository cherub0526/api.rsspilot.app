<?php

declare(strict_types=1);

namespace App\Utils\AI;

use RuntimeException;
use Hypervel\Support\Facades\Http;

class Completion
{
    public function __construct(
        private string $apiKey,
        private string $baseUri = 'https://openrouter.ai/api/v1',
        private array $extraHeaders = [],
    ) {
        $this->baseUri = rtrim($baseUri, '/');
    }

    public static function make(): self
    {
        return new self(
            apiKey: config('ai.openrouter.api_key'),
            baseUri: config('ai.openrouter.base_uri'),
            extraHeaders: array_filter([
                'HTTP-Referer' => config('ai.openrouter.site_url'),
                'X-Title'      => config('ai.openrouter.site_name'),
            ]),
        );
    }

    /**
     * @param array $options Additional parameters (e.g. max_tokens, temperature)
     * @return array Decoded JSON response
     * @throws RuntimeException on HTTP / JSON errors
     */
    public function completions(string $model, array $messages, array $options = []): array
    {
        $payload = array_merge([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => 20000,
            'temperature' => 0.7,
            'top_p'       => 1,
        ], $options);

        return $this->send('/chat/completions', $payload);
    }

    private function send(string $path, array $payload): array
    {
        return Http::withToken($this->apiKey)
            ->withHeaders($this->extraHeaders)
            ->timeout(60)
            ->acceptJson()
            ->post($this->baseUri . $path, $payload)
            ->json();
    }
}
