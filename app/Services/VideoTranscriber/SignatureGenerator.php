<?php

declare(strict_types=1);

namespace App\Services\VideoTranscriber;

class SignatureGenerator
{
    public function generate(array $payload, ?string $secretKey = null): string
    {
        $secretKey ??= config('services.videotranscriber.secret_key');

        return hash_hmac('sha256', $this->buildMessage($payload), $secretKey);
    }

    protected function buildMessage(array $payload): string
    {
        unset($payload['sign']);

        $payload = array_filter($payload, fn ($value) => $value !== null);

        ksort($payload);

        $pairs = [];
        foreach ($payload as $key => $value) {
            $pairs[] = $key . '=' . $this->stringifyValue($value);
        }

        return implode('&', $pairs);
    }

    protected function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            ksort($value);

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
