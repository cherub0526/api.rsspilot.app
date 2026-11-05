<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Plans;

use App\Models\Plan;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http404;
use App\Http\Resources\PlanResource;
use Psr\Http\Message\ResponseInterface;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\PlanResource as PlanSchema;

class HiddenController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/plans/hidden/{code}',
        operationId: 'api.v1.plans.hidden.show',
        summary: 'Get the exclusive plan behind a private link code',
        tags: ['Plans'],
        parameters: [
            new OAT\Parameter(
                name: 'code',
                description: 'The 32-character link code from plans:link',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string', pattern: '^[A-Za-z0-9]{32}$')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The hidden plan, same shape as an item of /v1/plans with hidden = true',
                content: new OAT\JsonContent(ref: PlanSchema::class)
            ),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    /**
     * 拿到連結的人才看得到的方案（hidden-plan-link）。
     *
     * 四種查不到的情況（碼不存在、方案不是 hidden、方案 inactive、沒有 price）在**同一個查詢**裡
     * 判斷，回應完全相同，不讓人從回應差異推測某組碼或某個方案是否存在（security SR-3）。
     * inactive 一定要擋：`plans:status` 關閉方案時不會清掉連結碼，靠這裡讓連結失效。
     *
     * 連結碼不寫進 log（SR-4）；成功與失敗都 `no-store`，碼不留在任何快取裡。
     */
    public function show(string $code): ResponseInterface
    {
        // @phpstan-ignore method.notFound
        $plan = Plan::query()
            ->where('visibility', Plan::VISIBILITY_HIDDEN)
            ->where('access_code', $code)
            ->active()
            ->whereHas('prices')
            ->with(['prices'])
            ->first();

        // 資料庫的等值比對之外再用 hash_equals 比一次：不依賴 collation 是否區分大小寫
        if (!$plan instanceof Plan || !hash_equals((string) $plan->access_code, $code)) {
            return response()
                ->json(['messages' => 'Resource not found.'], 404)
                ->withHeader('Cache-Control', 'no-store');
        }

        return response()
            ->json((new PlanResource($plan))->resolve())
            ->withHeader('Cache-Control', 'no-store');
    }
}
