<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Hypervel\Support\Facades\Http;

/**
 * Creem REST API 的薄包裝。
 *
 * **為什麼自己刻而不是用 SDK**：Creem 官方只出 TypeScript / Next.js SDK，沒有 PHP 版。
 * 好在它的 API 面很小（products / checkouts / subscriptions / customers），用
 * Hypervel 的 Http facade 包一層就夠，不必引入額外相依。
 *
 * 認證是 `x-api-key` 標頭（不是 Bearer）。test 與 production 是**兩個完全隔離的
 * 環境**，API key 不通用、資料不互通，切換只靠 CREEM_TEST_MODE 換 base URL——這點
 * 與 Paddle 的 PADDLE_SANDBOX 是同一個模式。
 */
class CreemClient
{
    private const BASE_URL_LIVE = 'https://api.creem.io/v1';

    private const BASE_URL_TEST = 'https://test-api.creem.io/v1';

    /** 對外請求的逾時（秒）。結帳是使用者在等的同步路徑，不能無限等下去。 */
    private const TIMEOUT = 15;

    public function baseUrl(): string
    {
        return $this->isTestMode() ? self::BASE_URL_TEST : self::BASE_URL_LIVE;
    }

    public function isTestMode(): bool
    {
        return filter_var(env('CREEM_TEST_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 建立 product。Creem 的價格與試用期都綁在 product 上，沒有 Paddle 那種
     * product → 多 price 的階層，所以我們每個 price 會對到一個（或兩個）product。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createProduct(array $payload): array
    {
        return $this->post('/products', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProduct(string $productId): array
    {
        return $this->get('/products/' . rawurlencode($productId));
    }

    /**
     * 建立結帳，回傳帶 checkout_url 的結果——Creem 是**轉址型**結帳，
     * 不像 Paddle 有 overlay SDK，所以前端拿到的是一個要跳過去的網址。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCheckout(array $payload): array
    {
        return $this->post('/checkouts', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCheckout(string $checkoutId): array
    {
        return $this->get('/checkouts/' . rawurlencode($checkoutId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array
    {
        return $this->get('/subscriptions/' . rawurlencode($subscriptionId));
    }

    /**
     * 取消訂閱。
     *
     * mode 預設 `at_period_end`——與 Paddle / Stripe 兩條路一致：使用者付過的那一期
     * 要讓他用完。傳 `immediately` 會當場斷，退款政策寫的是「用到期末」，不要違背。
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $subscriptionId, string $mode = 'at_period_end'): array
    {
        return $this->post(
            '/subscriptions/' . rawurlencode($subscriptionId) . '/cancel',
            ['mode' => $mode]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders($this->headers())
            ->post($this->baseUrl() . $path, $payload);

        return $this->decode($response->status(), (string) $response->body(), 'POST ' . $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders($this->headers())
            ->get($this->baseUrl() . $path);

        return $this->decode($response->status(), (string) $response->body(), 'GET ' . $path);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $apiKey = (string) env('CREEM_API_KEY', '');

        if ($apiKey === '') {
            throw new RuntimeException('CREEM_API_KEY is not configured.');
        }

        return [
            'x-api-key'    => $apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    /**
     * 統一的回應處理。
     *
     * 非 2xx 一律拋例外而不是回空陣列：吞掉錯誤會讓「結帳建立失敗」變成
     * 「使用者看到一個空白的付款頁」，而且沒有人會知道為什麼。
     *
     * @return array<string, mixed>
     */
    private function decode(int $status, string $body, string $context): array
    {
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                sprintf('Creem API %s failed with HTTP %d: %s', $context, $status, mb_substr($body, 0, 500))
            );
        }

        /** @var null|array<string, mixed> $decoded */
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                sprintf('Creem API %s returned a malformed body: %s', $context, mb_substr($body, 0, 500))
            );
        }

        return $decoded;
    }
}
