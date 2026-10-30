<?php

declare(strict_types=1);

namespace App\Utils\AI;

use RuntimeException;
use Hypervel\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

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
     * @param int $timeout 秒。預設 60；頻道 Skill 這類長輸入、長輸出的請求要自己放寬，
     *                     但必須小於 worker 的 --timeout（逾時是整個 worker 被砍）
     * @return array Decoded JSON response
     * @throws RuntimeException on HTTP / JSON errors
     */
    public function completions(string $model, array $messages, array $options = [], int $timeout = 60): array
    {
        $payload = array_merge([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => 20000,
            'temperature' => 0.7,
            'top_p'       => 1,
        ], $options);

        return $this->send('/chat/completions', $payload, $timeout);
    }

    /**
     * Send a streaming chat completion request.
     * Returns a PSR-7 ResponseInterface whose body is a readable SSE stream.
     *
     * @param array $options Additional parameters (e.g. max_tokens, temperature)
     */
    public function streamCompletions(string $model, array $messages, array $options = []): ResponseInterface
    {
        $payload = array_merge([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => 20000,
            'temperature' => 0.7,
            'top_p'       => 1,
            'stream'      => true,
        ], $options);

        return Http::withToken($this->apiKey)
            ->withHeaders($this->extraHeaders)
            ->timeout(120)
            ->acceptJson()
            ->withOptions(['stream' => true])
            ->post($this->baseUri . '/chat/completions', $payload)
            ->toPsrResponse();
    }

    private function send(string $path, array $payload, int $timeout): array
    {
        return Http::withToken($this->apiKey)
            ->withHeaders($this->extraHeaders)
            ->timeout($timeout)
            ->acceptJson()
            ->post($this->baseUri . $path, $payload)
            ->json();
    }
}
