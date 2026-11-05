<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `tokens_invalid_before`：簽發時間（JWT 的 iat，秒）早於它的 token 一律不收（token-invalidation S01、S03）。
 *
 * @internal
 * @coversNothing
 */
class UserTokensInvalidBeforeTest extends TestCase
{
    /** 2026-11-05 10:30:00 UTC */
    private const int RESET_AT = 1793874600;

    public function testEveryTokenIsAcceptedWhenNothingWasInvalidated(): void
    {
        $user = new User();

        $this->assertTrue($user->acceptsTokenIssuedAt(0));
        $this->assertTrue($user->acceptsTokenIssuedAt(self::RESET_AT));
    }

    #[DataProvider('issuedAt')]
    public function testTokensIssuedBeforeTheInvalidationSecondAreRejected(int $iat, bool $accepted): void
    {
        $user = new User();
        $user->tokens_invalid_before = Carbon::createFromTimestamp(self::RESET_AT);

        $this->assertSame($accepted, $user->acceptsTokenIssuedAt($iat));
    }

    /** @return array<string, array{int, bool}> */
    public static function issuedAt(): array
    {
        return [
            'an hour before'    => [self::RESET_AT - 3600, false],
            'one second before' => [self::RESET_AT - 1, false],
            // 重設回應發的 token 與欄位同一秒，不能被自己擋掉（NFR）
            'the same second'         => [self::RESET_AT, true],
            'one second after'        => [self::RESET_AT + 1, true],
            'missing iat (read as 0)' => [0, false],
        ];
    }

    /** 欄位存到資料庫只到秒；記憶體裡帶著毫秒也要照秒比，否則同一秒的新 token 會被擋。 */
    public function testSubSecondPrecisionIsIgnored(): void
    {
        $user = new User();
        $user->tokens_invalid_before = Carbon::createFromTimestamp(self::RESET_AT + 0.75);

        $this->assertTrue($user->acceptsTokenIssuedAt(self::RESET_AT));
        $this->assertFalse($user->acceptsTokenIssuedAt(self::RESET_AT - 1));
    }
}
