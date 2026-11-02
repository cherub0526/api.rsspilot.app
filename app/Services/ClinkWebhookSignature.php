<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Clink webhook 的簽章驗證，純函式。
 *
 * 五步都要做，而且都在 json_decode 之前、對原始 body 做：
 *
 * 1. `X-Clink-SignType` 必須是 `SHA256`
 * 2. `X-Clink-Timestamp` 是 Unix 毫秒，非數字就拒絕
 * 3. 與現在相差不超過 5 分鐘（過去與未來都算）。這是重放保護；Creem 的簽章沒有它
 * 4. HMAC-SHA256("{timestamp}.{rawBody}", signing secret)，hex
 * 5. 與 `X-Clink-Signature` 做常數時間比對
 *
 * 官方 TypeScript SDK 的 verifyAndGet() 只做第 4 步而且用 `===` 比對，不要照抄。
 */
final class ClinkWebhookSignature
{
    public const string SIGN_TYPE = 'SHA256';

    public const int TOLERANCE_MS = 5 * 60 * 1000;

    public static function isValid(
        string $signType,
        string $timestamp,
        string $signature,
        string $payload,
        string $secret,
        int $nowMs,
    ): bool {
        if ($secret === '' || $signature === '' || $signType !== self::SIGN_TYPE || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs($nowMs - (int) $timestamp) > self::TOLERANCE_MS) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha256', $timestamp . '.' . $payload, $secret),
            mb_strtolower($signature)
        );
    }
}
