<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Cache;

/**
 * Paddle webhook 來源 IP 的允許清單。
 *
 * 清單**不寫死**：唯一的事實來源是 Paddle 的 https://api.paddle.com/ips，
 * 它會變（Paddle 換基礎設施時就會變），寫死的那天 webhook 會整批被自己擋掉。
 * 這裡抓回來快取一天，抓不到就回 null——呼叫端據此放行而不是全擋，理由見
 * PaddleController::assertAllowedIp()。
 *
 * 這層是**縱深防禦**，不是主要防線：真正擋住偽造請求的是 Paddle-Signature 驗簽。
 * 反過來說也成立——IP 對了不代表內容沒被竄改，兩層都要在。
 */
class PaddleWebhookIpAllowlist
{
    /** sandbox 與 live 共用同一組 IP，端點只有這一個。 */
    private const SOURCE_URL = 'https://api.paddle.com/ips';

    private const CACHE_KEY = 'paddle:webhook:ipv4_cidrs';

    /** 快取一天。IP 變動是罕見事件，但也不該要重啟才會生效。 */
    private const CACHE_TTL = 86400;

    /**
     * 允許的 IPv4 CIDR 清單；取不到時回 null（「不知道」，不是「空清單」）。
     *
     * 這個區分很要緊：空清單會被讀成「誰都不准」，於是 Paddle 一時的故障就變成
     * 我們自己把所有 webhook 丟掉。null 讓呼叫端能選擇放行並留下 log。
     *
     * @return list<string>|null
     */
    public function cidrs(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::timeout(5)->get(self::SOURCE_URL);

            /** @var list<string> $cidrs */
            $cidrs = array_values(array_filter(
                (array) ($response->json()['data']['ipv4_cidrs'] ?? []),
                static fn ($cidr): bool => is_string($cidr) && $cidr !== ''
            ));
        } catch (Throwable $e) {
            Log::warning('Failed to fetch the Paddle webhook IP allowlist', ['error' => $e->getMessage()]);

            return null;
        }

        if ($cidrs === []) {
            Log::warning('The Paddle webhook IP allowlist came back empty', ['url' => self::SOURCE_URL]);

            return null;
        }

        Cache::put(self::CACHE_KEY, $cidrs, self::CACHE_TTL);

        return $cidrs;
    }

    /**
     * $ip 是否落在允許清單內。清單取不到時回 true（放行，見 cidrs()）。
     */
    public function allows(string $ip): bool
    {
        if (($cidrs = $this->cidrs()) === null) {
            return true;
        }

        foreach ($cidrs as $cidr) {
            if ($this->matches($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IPv4 CIDR 比對。Paddle 給的全是 /32，但還是照 prefix 算，
     * 免得哪天他們改成給網段時這裡靜靜地比錯。
     */
    private function matches(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $prefix = (int) $bits;

        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        // /0 要特判：PHP 的 << 32 是未定義行為，算出來不會是 0。
        if ($prefix === 0) {
            return true;
        }

        $mask = -1 << (32 - $prefix);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
