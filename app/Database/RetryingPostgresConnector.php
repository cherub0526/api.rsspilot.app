<?php

declare(strict_types=1);

namespace App\Database;

use Throwable;
use Hyperf\Database\PgSQL\Connectors\PostgresConnector;

/**
 * 開新連線遇到「could not send SSL negotiation packet」時重試一次。
 *
 * 連線池把閒置超過 max_idle_time 的連線關掉之後，下一個請求要重新連線；在 Railway
 * 的私有網路上，這次 SSL 交握的第一個 send 偶爾回 EAGAIN。Hyperf 的
 * DetectsLostConnections 不認得這句，於是不重試、直接讓那個請求 500——staging 的
 * 前端 build 預渲染首頁時就常撞上。立刻重連一次就會成功。
 *
 * 和「跨區域連不上」是同一句訊息但不同成因：跨區時每次都失敗，重試也沒用，
 * 見 .railway/README.md。
 */
class RetryingPostgresConnector extends PostgresConnector
{
    protected function causedByLostConnection(Throwable $e): bool
    {
        return parent::causedByLostConnection($e)
            || str_contains($e->getMessage(), 'could not send SSL negotiation packet');
    }
}
