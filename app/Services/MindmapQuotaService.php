<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use App\Models\MindmapUsage;
use App\Exceptions\MindmapQuotaExceededException;

/**
 * 每日心智圖產生額度。
 *
 * 與對話額度是分開的兩個桶子（plans.mindmap_limit / mindmap_usages）：畫了心智圖
 * 不該讓當天可以問的問題變少，反之亦然。機制本身與對話額度共用，見 DailyQuotaService。
 *
 * 命中既有心智圖（同一支影片、同一份摘要、同一種 AI 語言）時不會走到這裡——
 * 沒有實際推論就不扣額度。
 */
class MindmapQuotaService extends DailyQuotaService
{
    protected function usageModelClass(): string
    {
        return MindmapUsage::class;
    }

    protected function planLimitColumn(): string
    {
        return 'mindmap_limit';
    }

    protected function exceededException(DailyQuotaSnapshot $snapshot): Exception
    {
        return new MindmapQuotaExceededException($snapshot);
    }
}
