<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\CustomPrompts;

use App\Models\Media;
use App\Models\AiModel;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http422;
use App\Services\SummaryPreviewService;
use Psr\Http\Message\ResponseInterface;
use App\Validators\CustomPromptsValidator;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Controllers\Concerns\ResolvesUserPlan;

/**
 * 試跑一份摘要設定。
 *
 * 不落地任何東西：使用者還在調 prompt 的階段，這裡只回傳這次的產出讓他看效果。
 * 要保存得走 POST /v1/custom-prompts。
 */
class PreviewController extends AbstractController
{
    use ResolvesUserPlan;

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/custom-prompts/preview',
        operationId: 'api.v1.custom-prompts.preview.store',
        summary: 'Run a prompt against one video and return the summary preview',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['media_id', 'content'],
                properties: [
                    new OAT\Property(property: 'media_id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
                    new OAT\Property(property: 'content', type: 'string', example: '請以學習筆記的風格整理重點。'),
                    new OAT\Property(
                        property: 'model_id',
                        type: 'string',
                        nullable: true,
                        description: 'Falls back to the system default when omitted, or when the plan does not allow it.',
                        example: '01k9v7m2q8n4r6t0w3y5z7b1c9'
                    ),
                ]
            )
        ),
        tags: ['CustomPrompts'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Preview generated',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'sections',
                            type: 'array',
                            items: new OAT\Items(
                                properties: [
                                    new OAT\Property(property: 'heading', type: 'string', example: '主要論點'),
                                    new OAT\Property(
                                        property: 'items',
                                        type: 'array',
                                        items: new OAT\Items(type: 'string')
                                    ),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http422::class, response: 422),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        $params = $request->only(['media_id', 'content', 'model_id']);

        $validator = (new CustomPromptsValidator($params))->setPreviewRules();

        if (!$validator->passes()) {
            throw new InvalidRequestException($validator->errors()->toArray());
        }

        $media = $this->findMedia($request, (string) $params['media_id']);
        $captions = $this->captionsOf($media);

        $sections = app(SummaryPreviewService::class)->preview(
            (string) $params['content'],
            $captions,
            (string) $request->user()->aiLanguageName(),
            $this->providerModel($request, $params['model_id'] ?? null)
        );

        return response()->json(['sections' => $sections]);
    }

    /**
     * 只在使用者自己的影片庫裡找——別人的影片連字幕都不該被拿去跑。
     *
     * @throws InvalidRequestException
     */
    private function findMedia(Request $request, string $mediaId): Media
    {
        if (!$media = $request->user()->media()->find($mediaId)) {
            throw new InvalidRequestException(['media_id' => [__('validators.controllers.media.not_found')]]);
        }

        return $media;
    }

    /**
     * 拿主字幕的全文餵給模型。
     *
     * 字幕還沒好就沒有東西可摘要，這時擋下來而不是送空字串去讓模型憑空編：
     * 一份看起來像模像樣、其實與影片無關的摘要，比一句「還在處理」更難察覺。
     *
     * @throws InvalidRequestException
     */
    private function captionsOf(Media $media): string
    {
        $text = (string) ($media->captions()->orderByDesc('primary')->first()->text ?? '');

        if (trim($text) === '') {
            throw new InvalidRequestException(['media_id' => [__('validators.controllers.media.caption_not_found')]]);
        }

        return $text;
    }

    /**
     * 把使用者選的模型換成供應商代號。
     *
     * 沒選、或選了方案沒授權的，一律回空字串——TemplateCompletionManager 收到空字串
     * 就會依模板去查系統預設（見 OpenRouterModels::for()）。
     */
    private function providerModel(Request $request, ?string $modelId): string
    {
        $allowed = $this->allowedModelId($request, $modelId);

        if ($allowed === null) {
            return '';
        }

        return (string) (AiModel::query()->whereKey($allowed)->value('provider_model') ?? '');
    }
}
