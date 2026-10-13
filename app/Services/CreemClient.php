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
     * 建立折扣碼。
     *
     * 我們只用 `duration: once`——它「只套用在第一筆付款」，年繳就剛好是首年折價，
     * 續約自動回到 product 原價。Creem 的 product 建了就不能改價（見 Creem\Sync），
     * 所以「首年便宜、之後原價」沒有別的做法。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDiscount(array $payload): array
    {
        return $this->post('/discounts', $payload);
    }

    /**
     * 用 code 查折扣碼，**查不到回 null 而不是拋例外**。
     *
     * 這支是用來做冪等的：同一筆訂閱重試結帳時要沿用上次那一張，不能重複建
     * （code 唯一，重建會被 Creem 擋）。「還沒建過」是正常的查詢結果，所以 404
     * 要能跟真正的錯誤分開——這也是它不走 get() 的原因，那支非 2xx 一律拋。
     *
     * @return null|array<string, mixed>
     */
    public function findDiscount(string $code): ?array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders($this->headers())
            ->get($this->baseUrl() . '/discounts?discount_code=' . rawurlencode($code));

        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response->status(), (string) $response->body(), 'GET /discounts');
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
        // Creem 的單筆查詢是 query string，不是路徑參數：/checkouts/{id} 會回 404。
        return $this->get('/checkouts?checkout_id=' . rawurlencode($checkoutId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array
    {
        // 同上：/subscriptions/{id} 不存在，要用 ?subscription_id=。
        // （取消則是 POST /subscriptions/{id}/cancel，那個確實是路徑參數。）
        return $this->get('/subscriptions?subscription_id=' . rawurlencode($subscriptionId));
    }

    /**
     * 取消訂閱。
     *
     * mode 只接受 `scheduled` 與 `immediate`（查證自官方 SDK 的
     * CancelSubscriptionRequestEntity；文件沒列，傳別的值回 400）。預設 `scheduled`
     * ——與 Paddle / Stripe 兩條路一致：使用者付過的那一期要讓他用完。`immediate`
     * 會當場斷，退款政策寫的是「用到期末」，不要違背。
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $subscriptionId, string $mode = 'scheduled'): array
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
