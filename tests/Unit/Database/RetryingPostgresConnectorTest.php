<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use PDO;
use PDOException;
use Tests\TestCase;
use App\Database\RetryingPostgresConnector;
use Hyperf\Database\Connectors\ConnectionFactory;

/**
 * 開新的 pgsql 連線時，偶發的「could not send SSL negotiation packet」要重試一次，
 * 不能直接讓那個請求 500。
 *
 * @internal
 * @coversNothing
 */
class RetryingPostgresConnectorTest extends TestCase
{
    private const SSL_NEGOTIATION = 'SQLSTATE[08006] [7] could not send SSL negotiation packet: Resource temporarily unavailable';

    public function testThePgsqlDriverUsesTheRetryingConnector(): void
    {
        $connector = $this->app->get(ConnectionFactory::class)->createConnector(['driver' => 'pgsql']);

        $this->assertInstanceOf(RetryingPostgresConnector::class, $connector);
    }

    public function testRetriesOnceWhenSslNegotiationFails(): void
    {
        $connector = $this->connectorFailing([self::SSL_NEGOTIATION]);

        $this->assertInstanceOf(PDO::class, $connector->createConnection('pgsql:host=db', [], []));
        $this->assertSame(2, $connector->attempts);
    }

    public function testDoesNotRetryOtherConnectErrors(): void
    {
        $connector = $this->connectorFailing(['SQLSTATE[08006] [7] password authentication failed for user "app"']);

        try {
            $connector->createConnection('pgsql:host=db', [], []);
            $this->fail('expected the connect error to be thrown');
        } catch (PDOException $e) {
            $this->assertStringContainsString('password authentication failed', $e->getMessage());
        }
        $this->assertSame(1, $connector->attempts);
    }

    /**
     * @param list<string> $failures 依序拋出的錯誤訊息，用完之後才回傳連線
     */
    private function connectorFailing(array $failures): RetryingPostgresConnector
    {
        return new class($failures) extends RetryingPostgresConnector {
            public int $attempts = 0;

            /** @param list<string> $failures */
            public function __construct(private array $failures)
            {
            }

            protected function createPdoConnection($dsn, $username, $password, $options)
            {
                ++$this->attempts;
                $message = array_shift($this->failures);
                if ($message !== null) {
                    throw new PDOException($message);
                }

                return new PDO('sqlite::memory:');
            }
        };
    }
}
