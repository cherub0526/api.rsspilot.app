<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use Hypervel\Http\Request;
use App\Models\CustomPrompt;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Responses\Http422;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\OpenApi\Parameters\Path\PromptId;
use App\Validators\CustomPromptsValidator;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Resources\CustomPromptResource;
use App\Http\Controllers\Concerns\ResolvesUserPlan;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;
use App\OpenApi\Schemas\CustomPromptResource as CustomPromptSchema;

/**
 * 使用者收藏的 prompt 設定。
 *
 * 五支端點一律經由 $request->user()->customPrompts() 取用，不直接查
 * CustomPrompt::find()——關聯本身就是授權邊界，別人的設定連查都查不到。
 *
 * 自訂摘要是付費功能，但只有「會產生新設定」的 store 與 update 擋方案；
 * index / show / destroy 保持開放，否則付費過又降級的人會看不到、也刪不掉
 * 自己的資料。
 */
class CustomPromptsController extends AbstractController
{
    use ResolvesUserPlan;

    /**
     * 四支端點都回傳同一組關聯，集中一份免得某支漏載入，讓 Resource 靜靜少掉 key。
     */
    private const RELATIONS = ['model', 'sources'];

    #[OAT\Get(
        path: '/v1/custom-prompts',
        operationId: 'api.v1.custom-prompts.index',
        summary: 'List the current user custom prompts',
        security: [['bearerAuth' => []]],
        tags: ['CustomPrompts'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            type: 'array',
                            items: new OAT\Items(ref: CustomPromptSchema::class)
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $prompts = $request->user()
            ->customPrompts()
            ->with(self::RELATIONS)
            ->orderByDesc('id')
            ->get();

        return CustomPromptResource::collection($prompts);
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/custom-prompts',
        operationId: 'api.v1.custom-prompts.store',
        summary: 'Create a custom prompt',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['title', 'content'],
                properties: [
                    new OAT\Property(property: 'title', type: 'string', maxLength: 255, example: '學習筆記摘要'),
                    new OAT\Property(
                        property: 'content',
                        type: 'string',
                        example: '請以學習筆記的風格整理這部影片的重點…'
                    ),
                ]
            )
        ),
        tags: ['CustomPrompts'],
        responses: [
            new OAT\Response(
                response: 201,
                description: 'Created',
                content: new OAT\JsonContent(ref: CustomPromptSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http422::class, response: 422),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        $this->assertCustomSummaryEnabled($request);

        $params = $request->only(['title', 'content', 'model_id', 'source_ids']);

        $validator = (new CustomPromptsValidator($params))->setStoreRules();

        if (!$validator->passes()) {
            throw new InvalidRequestException($validator->errors()->toArray());
        }

        $prompt = $request->user()->customPrompts()->create([
            'title'    => $params['title'],
            'content'  => $params['content'],
            'model_id' => $this->allowedModelId($request, $params['model_id'] ?? null),
        ]);

        $prompt->sources()->sync($this->resolveSourceIds($request, $params['source_ids'] ?? []));

        return (new CustomPromptResource($prompt->load(self::RELATIONS)))
            ->toResponse()
            ->withStatus(201);
    }

    /**
     * @throws NotFoundHttpException
     */
    #[OAT\Get(
        path: '/v1/custom-prompts/{promptId}',
        operationId: 'api.v1.custom-prompts.show',
        summary: 'Show one custom prompt',
        security: [['bearerAuth' => []]],
        tags: ['CustomPrompts'],
        parameters: [
            new OAT\Parameter(ref: PromptId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(ref: CustomPromptSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function show(Request $request, string $promptId): CustomPromptResource
    {
        return new CustomPromptResource($this->findOrFail($request, $promptId));
    }

    /**
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
     */
    #[OAT\Put(
        path: '/v1/custom-prompts/{promptId}',
        operationId: 'api.v1.custom-prompts.update',
        summary: 'Replace a custom prompt',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['title', 'content'],
                properties: [
                    new OAT\Property(property: 'title', type: 'string', maxLength: 255, example: '學習筆記摘要'),
                    new OAT\Property(
                        property: 'content',
                        type: 'string',
                        example: '請以學習筆記的風格整理這部影片的重點…'
                    ),
                ]
            )
        ),
        tags: ['CustomPrompts'],
        parameters: [
            new OAT\Parameter(ref: PromptId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Updated',
                content: new OAT\JsonContent(ref: CustomPromptSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
            new OAT\Response(ref: Http422::class, response: 422),
        ]
    )]
    public function update(Request $request, string $promptId): CustomPromptResource
    {
        $this->assertCustomSummaryEnabled($request);

        $params = $request->only(['title', 'content', 'model_id', 'source_ids']);

        $validator = (new CustomPromptsValidator($params))->setUpdateRules();

        if (!$validator->passes()) {
            throw new InvalidRequestException($validator->errors()->toArray());
        }

        // 先驗證再查：欄位不合法時不需要碰資料庫，也讓 422 的判斷不受 id 存不存在影響。
        $prompt = $this->findOrFail($request, $promptId);

        $prompt->update([
            'title'    => $params['title'],
            'content'  => $params['content'],
            'model_id' => $this->allowedModelId($request, $params['model_id'] ?? null),
        ]);

        // PUT 是整筆取代：沒送 source_ids 就是清空，不是保持原狀。
        $prompt->sources()->sync($this->resolveSourceIds($request, $params['source_ids'] ?? []));

        return new CustomPromptResource($prompt->load(self::RELATIONS));
    }

    /**
     * @throws NotFoundHttpException
     */
    #[OAT\Delete(
        path: '/v1/custom-prompts/{promptId}',
        operationId: 'api.v1.custom-prompts.destroy',
        summary: 'Delete a custom prompt',
        security: [['bearerAuth' => []]],
        tags: ['CustomPrompts'],
        parameters: [
            new OAT\Parameter(ref: PromptId::class),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Deleted'),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    public function destroy(Request $request, string $promptId): ResponseInterface
    {
        $this->findOrFail($request, $promptId)->delete();

        return response()->make(self::RESPONSE_OK);
    }

    /**
     * 過濾出「確實是這個使用者訂閱的」來源。
     *
     * custom_prompt_sources 沒有 user_id，中介表本身擋不住掛上別人的 source，
     * 所以授權在這裡做：拿使用者的訂閱關聯去交集，不在裡面的直接丟掉。
     * 同樣不擋請求——來源可能在填表期間被取消訂閱。
     *
     * @param array<int, mixed> $sourceIds
     * @return array<int, string>
     */
    private function resolveSourceIds(Request $request, array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        return $request->user()
            ->sources()
            ->whereIn('sources.id', array_map('strval', $sourceIds))
            ->pluck('sources.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * 只在當前使用者的設定裡找。別人的 id 與不存在的 id 走同一條 404——
     * 回應不該透露這筆資料存不存在。
     *
     * @throws NotFoundHttpException
     */
    private function findOrFail(Request $request, string $promptId): CustomPrompt
    {
        if (!$prompt = $request->user()->customPrompts()->with(self::RELATIONS)->find($promptId)) {
            throw new NotFoundHttpException();
        }

        return $prompt;
    }
}
