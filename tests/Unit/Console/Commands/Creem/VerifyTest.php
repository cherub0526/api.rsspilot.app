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
     * 檢查要照實際生效的值報（見 Subscription::defaultPaymentMethod()）：
     * 明寫 creem 才會走 Creem；還寫著已移除的 paddle 時實際生效的是 clink，
     * 要報「新的結帳不會走 Creem」，不能誤報成會走 Creem。
     */
    public function testReportsTheEffectiveDefaultProvider(): void
    {
        $previous = getenv('PAYMENT_DEFAULT_PROVIDER');

        try {
            foreach (['creem' => true, 'paddle' => false] as $configured => $routesToCreem) {
                putenv("PAYMENT_DEFAULT_PROVIDER={$configured}");
                $_ENV['PAYMENT_DEFAULT_PROVIDER'] = $_SERVER['PAYMENT_DEFAULT_PROVIDER'] = $configured;

                $command = $this->artisan('creem:verify');
                $routesToCreem
                    ? $command->expectsOutputToContain('新結帳會走 Creem')->doesntExpectOutputToContain('新的結帳不會走 Creem')
                    : $command->expectsOutputToContain('新的結帳不會走 Creem')->doesntExpectOutputToContain('新結帳會走 Creem');
                $command->run();
            }
        } finally {
            putenv($previous === false ? 'PAYMENT_DEFAULT_PROVIDER' : 'PAYMENT_DEFAULT_PROVIDER=' . $previous);
            $_ENV['PAYMENT_DEFAULT_PROVIDER'] = $_SERVER['PAYMENT_DEFAULT_PROVIDER'] = $previous === false ? null : $previous;
        }
    }
}
