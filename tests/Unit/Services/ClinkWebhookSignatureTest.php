<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\ClinkWebhookSignature;

/**
 * @internal
 * @coversNothing
 */
class ClinkWebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_unit';

    private const NOW_MS = 1_761_900_000_000;

    private const BODY = '{"id":"event_1","type":"subscription.activated","data":{"object":{}}}';

    public function testAcceptsAFreshCorrectSignature(): void
    {
        $this->assertTrue($this->verify());
    }

    /** 簽章字串是「毫秒時間戳 + . + 原始 body」，不是只有 body。 */
    public function testSignsTheTimestampTogetherWithTheBody(): void
    {
        $bodyOnly = hash_hmac('sha256', self::BODY, self::SECRET);

        $this->assertFalse($this->verify(signature: $bodyOnly));
    }

    public function testAcceptsAnUppercaseHexSignature(): void
    {
        $this->assertTrue($this->verify(signature: mb_strtoupper($this->sign((string) self::NOW_MS))));
    }

    public function testRejectsAnotherSignType(): void
    {
        $this->assertFalse($this->verify(signType: 'MD5'));
        $this->assertFalse($this->verify(signType: ''));
    }

    public function testRejectsANonNumericTimestamp(): void
    {
        foreach (['', 'abc', '1761900000000.5', '-1761900000000'] as $timestamp) {
            $this->assertFalse($this->verify(timestamp: $timestamp, signature: $this->sign($timestamp)), $timestamp);
        }
    }

    /** 5 分鐘的時效窗，過去與未來兩個方向都要擋。 */
    public function testRejectsATimestampOutsideTheFiveMinuteWindow(): void
    {
        $fiveMinutes = 5 * 60 * 1000;

        foreach ([self::NOW_MS - $fiveMinutes - 1, self::NOW_MS + $fiveMinutes + 1] as $ts) {
            $this->assertFalse($this->verify(timestamp: (string) $ts, signature: $this->sign((string) $ts)), (string) $ts);
        }

        foreach ([self::NOW_MS - $fiveMinutes, self::NOW_MS + $fiveMinutes] as $ts) {
            $this->assertTrue($this->verify(timestamp: (string) $ts, signature: $this->sign((string) $ts)), (string) $ts);
        }
    }

    public function testRejectsATamperedBody(): void
    {
        $this->assertFalse($this->verify(body: str_replace('activated', 'cancelled', self::BODY)));
    }

    public function testRejectsAWrongOrEmptySecret(): void
    {
        $this->assertFalse($this->verify(secret: 'another'));
        $this->assertFalse($this->verify(secret: '', signature: hash_hmac('sha256', self::NOW_MS . '.' . self::BODY, '')));
    }

    public function testRejectsAMissingSignature(): void
    {
        $this->assertFalse($this->verify(signature: ''));
    }

    private function sign(string $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . self::BODY, self::SECRET);
    }

    private function verify(
        string $signType = 'SHA256',
        ?string $timestamp = null,
        ?string $signature = null,
        string $body = self::BODY,
        string $secret = self::SECRET,
    ): bool {
        $timestamp ??= (string) self::NOW_MS;

        return ClinkWebhookSignature::isValid(
            signType: $signType,
            timestamp: $timestamp,
            signature: $signature ?? $this->sign($timestamp),
            payload: $body,
            secret: $secret,
            nowMs: self::NOW_MS,
        );
    }
}
