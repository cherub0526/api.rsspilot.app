<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Plan;
use Hypervel\Http\Request;
use App\Services\SubscriptionService;

/**
 * 取得使用者「當下生效」的方案。
 *
 * SubscriptionService 已經處理了沒有訂閱時退回免費月方案的情形，這裡只是把
 * 「取訂閱 → 取方案」這兩步收在一處，免得每個呼叫端各抄一遍。
 */
trait ResolvesUserPlan
{
    protected function userPlan(Request $request): ?Plan
    {
        $service = app(SubscriptionService::class);

        return $service->getUserSubscriptionPlan(
            $service->getUserSubscription((string) $request->user()->getKey())
        );
    }
}
