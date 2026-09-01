<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Plan;
use App\Models\AiModel;
use Hypervel\Http\Request;
use App\Services\SubscriptionService;
use App\Exceptions\InvalidRequestException;

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

    /**
     * 自訂 AI 摘要是付費功能，擋下方案沒開通的使用者。
     *
     * 判準用 plans.custom_summary_enabled 而不是「方案是不是 Pro 以上」——
     * 那個欄位本來就是產品用來表達這件事的，寫死方案名稱會讓日後新增方案或
     * 調整權益時，程式與資料各說各話。
     *
     * 沒有方案時一併擋下：無從判斷權益的預設是不給。
     *
     * @throws InvalidRequestException
     */
    protected function assertCustomSummaryEnabled(Request $request): void
    {
        $plan = $this->userPlan($request);

        if ($plan !== null && (bool) $plan->getAttribute('custom_summary_enabled')) {
            return;
        }

        throw new InvalidRequestException(
            ['plan' => [__('validators.controllers.custom_prompts.plan_required')]]
        );
    }

    /**
     * 送進來的模型必須存在、開放選用，而且是這個使用者的方案有授權的，
     * 否則一律回 null 當成「不指定」。
     *
     * 方案檢查不能只做在 GET /v1/ai-models 上——那只是讓選單不顯示，直接送一個
     * 沒授權的 id 仍然會被接受。授權要在每個接收 model_id 的端點都擋。
     *
     * 不擋下請求而是靜默退回 null：模型可能在使用者開著表單的期間被下架、方案也
     * 可能剛好到期，那不是他填錯了。沒有模型時推論端會退回系統預設。
     */
    protected function allowedModelId(Request $request, ?string $modelId): ?string
    {
        if ($modelId === null || $modelId === '') {
            return null;
        }

        $plan = $this->userPlan($request);

        if ($plan === null) {
            return null;
        }

        $allowed = AiModel::query()
            ->where('enabled', true)
            ->whereKey($modelId)
            ->whereHas('plans', fn ($query) => $query->whereKey($plan->getKey()))
            ->exists();

        return $allowed ? $modelId : null;
    }
}
