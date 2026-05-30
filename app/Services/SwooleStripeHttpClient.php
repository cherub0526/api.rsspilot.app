<?php

declare(strict_types=1);

namespace App\Services;

use Stripe\HttpClient\CurlClient;
use Stripe\HttpClient\ClientInterface;

/**
 * Wraps CurlClient so each Stripe API call gets its own fresh CURL handle.
 *
 * In Swoole's coroutine environment, `ApiRequestor::$_httpClient` is a
 * process-level static singleton. The default CurlClient reuses one CURL
 * handle (persistent connections), which causes a race condition when two
 * coroutines make Stripe API calls concurrently (e.g. webhook +
 * checkout-session arriving simultaneously after a payment).
 *
 * By creating a new CurlClient per request() call, each coroutine gets its
 * own handle and there is no shared mutable state between concurrent calls.
 */
class SwooleStripeHttpClient implements ClientInterface
{
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $client = new CurlClient();
        return $client->request($method, $absUrl, $headers, $params, $hasFile, $apiMode, $maxNetworkRetries);
    }
}
