<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\AiModel;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\Http\Resources\AiModelResource;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\ResolvesUserPlan;
use App\OpenApi\Schemas\AiModelResource as AiModelSchema;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 使用者可選的 AI 模型型錄。
 *
 * 只讀：型錄由 seeder 與 migration 維護，不開放 API 增修。
 */
class AiModelsController extends AbstractController
{
    use ResolvesUserPlan;

    #[OAT\Get(
        path: '/v1/ai-models',
        operationId: 'api.v1.ai-models.index',
        summary: 'List the AI models a user can pick',
        security: [['bearerAuth' => []]],
        tags: ['AiModels'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            type: 'array',
                            items: new OAT\Items(ref: AiModelSchema::class)
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        // 停用的模型不出現在選單裡。已經被既有設定選中的停用模型也不會出現，
        // 那是刻意的——它會在下次儲存時靜默退回不指定（見 CustomPromptsController）。
        $plan = $this->userPlan($request);

        if ($plan === null) {
            // 連免費方案都查不到（例如方案表是空的）就沒有依據判斷誰能用什麼。
            // 這時回空清單而不是回全部——「不知道」的安全預設是不給。
            return AiModelResource::collection(collect());
        }

        // plans 必須載入：min_plan 是從它推導的，沒載入 Resource 會回 null。
        $models = AiModel::query()
            ->with('plans')
            ->where('enabled', true)
            ->whereHas('plans', fn ($query) => $query->whereKey($plan->getKey()))
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        return AiModelResource::collection($models);
    }
}
