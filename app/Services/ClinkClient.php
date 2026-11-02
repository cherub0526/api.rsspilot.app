<?php

declare(strict_types=1);

namespace App\Services;

use LogicException;
use RuntimeException;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Http;
use Hypervel\HttpClient\PendingRequest;

/**
 * Clink（clinkbill.com，Merchant of Record）REST API 的薄包裝。
 *
 * Clink 沒有 PHP SDK，形狀照 CreemClient。所有 Clink 特有的格式問題在這一層吃掉，
 * service 只拿到乾淨的陣列：
 *
 * - 認證是 `X-API-Key` 加上 `X-Timestamp`（毫秒）。production 只容許與平台時間差
 *   2 分鐘、sandbox 寬鬆到 ±24 小時，所以時間戳**每次請求現算**，快取的話 sandbox
 *   全過、production 全掛。
 * - 回應包在 `{code, msg, data}` 信封裡。HTTP 200 但 `code` 不是 200 一樣是失敗。
 *   列表端點例外：資料放在 `rows`，沒有 `data`。
 * - sandbox 與 production 是兩個完全分開的帳號環境，靠 CLINK_TEST_MODE 換 base URL。
 */
class ClinkClient
{
    private const BASE_URL_LIVE = 'https://api.clinkbill.com/api';

    private const BASE_URL_TEST = 'https://uat-api.clinkbill.com/api';

    /** 對外請求的逾時（秒）。結帳是使用者在等的同步路徑。 */
    private const TIMEOUT = 15;

    /** Clink 信封裡代表成功的 code。 */
    private const CODE_SUCCESS = 200;

    public function baseUrl(): string
    {
        return $this->isTestMode() ? self::BASE_URL_TEST : self::BASE_URL_LIVE;
    }

    public function isTestMode(): bool
    {
        return filter_var(env('CLINK_TEST_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 上傳商品圖片（multipart 欄位名 `file`）。建立 product 時 `image` 必填，填的是
     * 這裡回傳的 `ossId`。
     *
     * @return array<string, mixed>
     */
    public function uploadProductImage(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Cannot read the product image at {$path}.");
        }

        $response = $this->request()
            ->attach('file', $contents, basename($path))
            ->post($this->baseUrl() . '/product/image/upload');

        return $this->data($response->status(), (string) $response->body(), 'POST /product/image/upload');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createProduct(array $payload): array
    {
        return $this->send('POST', '/product', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProduct(string $productId): array
    {
        return $this->send('GET', '/product/' . rawurlencode($productId));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPrice(array $payload): array
    {
        return $this->send('POST', '/price', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrice(string $priceId): array
    {
        return $this->send('GET', '/price/' . rawurlencode($priceId));
    }

    /**
     * 修改 price。Clink 每次修改產生新的 snapshot：新訂閱用新值，既有訂閱維持原值。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePrice(string $priceId, array $payload): array
    {
        return $this->send('PUT', '/price/' . rawurlencode($priceId), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $payload): array
    {
        return $this->send('POST', '/checkout/session', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCheckoutSession(string $sessionId): array
    {
        return $this->send('GET', '/checkout/session/' . rawurlencode($sessionId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->send('GET', '/subscription/' . rawurlencode($subscriptionId));
    }

    /**
     * 取消訂閱。`reason` 是 Clink 的必填欄位（1 到 255 字）。
     *
     * 預設期末取消：退款政策寫的是「用到期末」，立即取消也不會退這一期的錢。
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $subscriptionId, string $reason, bool $immediately = false): array
    {
        return $this->send('POST', '/subscription/' . rawurlencode($subscriptionId) . '/cancel', [
            'reason'            => $reason,
            'cancelImmediately' => $immediately,
        ]);
    }

    /**
     * 已註冊的 webhook endpoint。列表端點的資料在 `rows`，不在 `data`。
     *
     * @return list<array<string, mixed>>
     */
    public function listWebhookEndpoints(): array
    {
        $response = $this->request()->get($this->baseUrl() . '/webhook/endpoints');
        $decoded = $this->decode($response->status(), (string) $response->body(), 'GET /webhook/endpoints');
        $rows = $decoded['rows'] ?? null;

        if (!is_array($rows)) {
            throw new RuntimeException('Clink API GET /webhook/endpoints returned no rows.');
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload = []): array
    {
        $url = $this->baseUrl() . $path;
        $request = $this->request()->asJson();

        $response = match ($method) {
            'GET'   => $request->get($url),
            'PUT'   => $request->put($url, $payload),
            'POST'  => $request->post($url, $payload),
            default => throw new LogicException("Unsupported Clink API method {$method}"),
        };

        return $this->data($response->status(), (string) $response->body(), "{$method} {$path}");
    }

    private function request(): PendingRequest
    {
        $apiKey = (string) env('CLINK_API_KEY', '');

        if ($apiKey === '') {
            throw new RuntimeException('CLINK_API_KEY is not configured.');
        }

        return Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->withHeaders([
                'X-API-Key'   => $apiKey,
                'X-Timestamp' => (string) (int) floor(microtime(true) * 1000),
            ]);
    }

    /**
     * 信封的 `data`；不是物件就當失敗。
     *
     * @return array<string, mixed>
     */
    private function data(int $status, string $body, string $context): array
    {
        $data = $this->decode($status, $body, $context)['data'] ?? null;

        if (!is_array($data)) {
            throw new RuntimeException("Clink API {$context} returned no data.");
        }

        return $data;
    }

    /**
     * HTTP 2xx、body 是 JSON、信封 `code` 是 200，三道都過才算成功。
     *
     * 例外訊息只帶 method、path、HTTP 狀態與信封 code。`msg` 可能帶客戶資料，另外
     * 截斷後記進 log 方便排查。
     *
     * @return array<string, mixed>
     */
    private function decode(int $status, string $body, string $context): array
    {
        $decoded = json_decode($body, true);
        $code = is_array($decoded) ? ($decoded['code'] ?? null) : null;

        if ($status >= 200 && $status < 300 && $code === self::CODE_SUCCESS) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        Log::warning('Clink API request failed', [
            'context' => $context,
            'status'  => $status,
            'code'    => $code,
            'msg'     => is_array($decoded) ? mb_substr((string) ($decoded['msg'] ?? ''), 0, 200) : null,
        ]);

        throw new RuntimeException(sprintf(
            'Clink API %s failed with HTTP %d, code %s.',
            $context,
            $status,
            is_scalar($code) ? (string) $code : 'none'
        ));
    }
}
