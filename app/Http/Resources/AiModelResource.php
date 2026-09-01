<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Hypervel\Http\Resources\Json\JsonResource;

class AiModelResource extends JsonResource
{
    public ?string $wrap = null;

    /**
     * 包含這個模型的最低階方案，給前端當分組依據。
     *
     * 這個值是從 plan_ai_models 推導的，不是另外存一個標籤欄位。存標籤的話，
     * 把 Opus 5 開放給 Free 之後標籤仍會寫著高階方案，前端就得決定要信哪一個；
     * 從授權關聯推導則不可能對不上。
     *
     * plans 未 eager load 時回 null——呼叫端沒載入就不該拿到半真半假的分組。
     *
     * @return null|array<string, mixed>
     */
    private function minPlan(): ?array
    {
        if (!$this->resource->relationLoaded('plans')) {
            return null;
        }

        $plan = $this->resource->plans->sortBy('sort')->first();

        if ($plan === null) {
            return null;
        }

        return [
            'id'    => strval($plan->id),
            'title' => strval($plan->title ?? ''),
            'sort'  => (int) $plan->sort,
        ];
    }

    public function toArray(): array
    {
        return [
            'id'                => strval($this->resource->id),
            'name'              => strval($this->resource->getAttribute('name') ?? ''),
            'supports_thinking' => (bool) $this->resource->getAttribute('supports_thinking'),
            // provider_model 與價格都不輸出：前者是路由到供應商的內部代號，
            // 後者是我們的進價——攤在對外 API 上等於把成本結構與毛利公開。
            'min_plan' => $this->minPlan(),
        ];
    }
}
