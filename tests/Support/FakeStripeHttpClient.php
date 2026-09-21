<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stripe SDK 的假 HTTP 層。
 *
 * StripeSubscriptionService 內部一律 `new StripeClient()`，不從容器取，
 * 所以沒辦法用 `$this->mock()` 換掉。改攔在更底下一層：SDK 所有請求最後
 * 都走 `ApiRequestor::httpClient()` 這個 static，把它換成本類別就能在不
 * 對外發請求的前提下跑完整條 createCheckout()。
 *
 * 回應以 "post /v1/customers" 這種 "{method} {path}" 當 key；沒準備回應
 * 的請求直接丟例外，免得測試在無聲回 null 之後才在別的地方炸開。
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array}> */
    public array $requests = [];

    /** @param array<string, array> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtolower((string) $method);
        $path = (string) parse_url($absUrl, PHP_URL_PATH);

        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => (array) $params];

        $key = "{$method} {$path}";

        if (!array_key_exists($key, $this->responses)) {
            throw new RuntimeException("FakeStripeHttpClient: 沒有為 [{$key}] 準備回應");
        }

        return [json_encode($this->responses[$key]), 200, []];
    }

    /**
     * 取出打到某個 endpoint 的所有請求。
     *
     * @return list<array{method: string, path: string, params: array}>
     */
    public function requestsFor(string $method, string $path): array
    {
        $method = strtolower($method);

        return array_values(array_filter(
            $this->requests,
            fn (array $request) => $request['method'] === $method && $request['path'] === $path
        ));
    }
}
