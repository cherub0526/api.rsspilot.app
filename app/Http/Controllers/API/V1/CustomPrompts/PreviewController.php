<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\CustomPrompts;

use Throwable;
use App\Models\Media;
use App\Models\AiModel;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http422;
use App\OpenApi\Responses\Http429;
use App\Services\ChatQuotaService;
use App\Services\DailyQuotaSnapshot;
use App\Services\SummaryPreviewService;
use Psr\Http\Message\ResponseInterface;
use App\Validators\CustomPromptsValidator;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Exceptions\ChatQuotaExceededException;
use App\Http\Controllers\Concerns\ResolvesUserPlan;

/**
 * 試跑一份摘要設定。
 *
 * 不落地任何東西：使用者還在調 prompt 的階段，這裡只回傳這次的產出讓他看效果。
 * 要保存得走 POST /v1/custom-prompts。
 *
 * 自訂摘要是付費功能，方案沒開通就擋在最前面——這一支每次呼叫都會真的送推論，
 * 不擋等於讓免費方案免費用掉我們的成本。
 *
 * 方案的開通與否只是第一道閘：開通之後，試跑仍然是「按一下就送一次推論」的端點，
 * 而它不落地任何東西，所以沒有任何天然的節流。再吃每日額度，付費方案也能靠連按
 * 這顆按鈕把成本推到任意高。
 */
class PreviewController extends AbstractController
{
    use ResolvesUserPlan;

    /**
     * 試跑與 AI 對話共用同一份每日額度（plans.chat_limit / chat_usages）。
     *
     * 不另開一個 preview_limit：兩者都是「使用者主動觸發的一次推論」，成本同源，
     * 分兩個桶等於把同一筆預算拆成兩份各自見底，使用者也得記兩組數字。共用的代價
     * 是試跑會吃掉當天的提問次數——這是刻意的，額度本來就該反映花掉的錢。
     */
    public function __construct(private ChatQuotaService $quota)
    {
    }

    /**
     * @throws InvalidRequestException
     * @throws ChatQuotaExceededException 當日 AI 額度已用盡
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
                        new OAT\Property(property: 'short_summary', type: 'string', example: '一句話總結。'),
                        new OAT\Property(
                            property: 'long_summary',
                            properties: [
                                new OAT\Property(property: 'content', type: 'string', example: '完整的長摘要。'),
                                new OAT\Property(
                                    property: 'key_points',
                                    type: 'array',
                                    items: new OAT\Items(type: 'string')
                                ),
                                new OAT\Property(
                                    property: 'keywords',
                                    type: 'array',
                                    items: new OAT\Items(type: 'string')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http422::class, response: 422),
            new OAT\Response(ref: Http429::class, response: 429),
        ]
    )]
    public function store(Request $request): ResponseInterface
    {
        $this->assertCustomSummaryEnabled($request);

        $params = $request->only(['media_id', 'content', 'model_id']);

        $validator = (new CustomPromptsValidator($params))->setPreviewRules();

        if (!$validator->passes()) {
            throw new InvalidRequestException($validator->errors()->toArray());
        }

        $media = $this->findMedia($request, (string) $params['media_id']);
        $captions = $this->captionsOf($media);

        // 額度扣在所有驗證之後：指到別人的影片、字幕還沒好——這些是請求本身有問題，
        // 一次推論都還沒發生，不該先扣一次再退還。
        $quota = $this->quota->consume($request->user());

        try {
            $summary = app(SummaryPreviewService::class)->preview(
                (string) $params['content'],
                $captions,
                (string) $request->user()->aiLanguageName(),
                $this->providerModel($request, $params['model_id'] ?? null),
                $request->user()
            );
        } catch (Throwable $e) {
            // 試跑不是串流，使用者要嘛拿到完整結果、要嘛什麼都沒有；沒有
            // ChatController 那種「已經吐了一半」的中間狀態，失敗一律退還。
            $this->quota->release($request->user(), $quota);

            throw $e;
        }

        // 形狀與 summaries.text 一致，前端可以沿用既有的摘要渲染。
        return $this->withQuotaHeaders(response()->json($summary), $quota);
    }

    /**
     * 成功的回應也帶 X-RateLimit-*，前端不必等到被擋才知道今天還剩幾次。
     * 不限制的方案不會有這組 header（見 DailyQuotaSnapshot::headers()）。
     */
    private function withQuotaHeaders(ResponseInterface $response, DailyQuotaSnapshot $quota): ResponseInterface
    {
        foreach ($quota->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
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
