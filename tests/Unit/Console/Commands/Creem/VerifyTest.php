<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Creem;

use Tests\TestCase;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @coversNothing
 */
class VerifyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 正式環境的 PAYMENT_DEFAULT_PROVIDER 若還寫著已移除的 paddle，實際生效的是
     * creem（見 Subscription::defaultPaymentMethod()），檢查要照實際生效的值報，
     * 不能誤報「新的結帳不會走 Creem」。
     */
    public function testReportsTheEffectiveDefaultProvider(): void
    {
        $previous = getenv('PAYMENT_DEFAULT_PROVIDER');
        putenv('PAYMENT_DEFAULT_PROVIDER=paddle');
        $_ENV['PAYMENT_DEFAULT_PROVIDER'] = $_SERVER['PAYMENT_DEFAULT_PROVIDER'] = 'paddle';

        try {
            $this->artisan('creem:verify')
                ->expectsOutputToContain('新結帳會走 Creem')
                ->doesntExpectOutputToContain('新的結帳不會走 Creem')
                ->run();
        } finally {
            putenv($previous === false ? 'PAYMENT_DEFAULT_PROVIDER' : 'PAYMENT_DEFAULT_PROVIDER=' . $previous);
            $_ENV['PAYMENT_DEFAULT_PROVIDER'] = $_SERVER['PAYMENT_DEFAULT_PROVIDER'] = $previous === false ? null : $previous;
        }
    }
}
