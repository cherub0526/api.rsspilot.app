<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\ChatUsage;
use App\Exceptions\ChatQuotaExceededException;

/**
 * 每日 AI 提問額度。
 *
 * 上限來自使用者「當下生效方案」的 plans.chat_limit（0 = 不限制），用量記在
 * chat_usages。扣點、退還、重置時間的機制全部在 DailyQuotaService。
 */
class ChatQuotaService extends DailyQuotaService
{
    protected function usageModelClass(): string
    {
        return ChatUsage::class;
    }

    protected function planLimitColumn(): string
    {
        return 'chat_limit';
    }

    protected function exceededException(DailyQuotaSnapshot $snapshot): Exception
    {
        return new ChatQuotaExceededException($snapshot);
    }
}
