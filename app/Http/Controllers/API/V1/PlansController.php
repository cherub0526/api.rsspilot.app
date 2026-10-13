<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Plan;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\Http\Resources\PlanResource;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\PlanResource as PlanSchema;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class PlansController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/plans',
        operationId: 'api.v1.plans.index',
        summary: 'List available subscription plans',
        tags: ['Plans'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'List of active subscription plans',
                content: new OAT\JsonContent(
                    type: 'array',
                    items: new OAT\Items(ref: PlanSchema::class)
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
        ]
    )]
    public function index(): AnonymousResourceCollection
    {
        $plans = Plan::query()->active()
            ->with(['prices'])
            ->orderBy('sort', 'asc')
            ->get();

        $plans = $plans->filter(function (Plan $plan) {
            return $plan->prices->isNotEmpty();
        });

        // 新帳號的影片額度加碼不是方案欄位（它對每個方案都成立，只是取 max），
        // 所以掛在 meta 上。定價頁要據此說明「新帳號前 N 天有 M 支」，天數與支數
        // 必須從這裡來——寫死在前端的話，改了設定檔對外說法就不一樣了。
        return PlanResource::collection($plans)->additional([
            'meta' => [
                'new_account_video_boost' => [
                    'days'  => max(0, (int) config('subscription.video_boost.days', 0)),
                    'limit' => max(0, (int) config('subscription.video_boost.limit', 0)),
                ],
            ],
        ]);
    }
}
