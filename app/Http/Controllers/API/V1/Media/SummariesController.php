<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use App\Models\Media;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Http\Resources\SummaryResource;
use Psr\Http\Message\ResponseInterface;
use App\OpenApi\Parameters\Path\MediaId;
use App\Services\Export\SummaryExporter;
use App\Exceptions\NotFoundHttpException;
use App\OpenApi\Parameters\Path\SummaryId;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\Concerns\SendsDownloads;
use App\Http\Controllers\Concerns\ResolvesUserPlan;
use App\OpenApi\Schemas\SummaryResource as SummarySchema;

class SummariesController
{
    use ResolvesUserPlan;
    use SendsDownloads;

    #[OAT\Get(
        path: '/v1/media/{mediaId}/summaries',
        operationId: 'api.v1.media.summaries.index',
        summary: 'Get the summary for a media item',
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Summary resource or empty array if no summary exists',
                content: new OAT\JsonContent(
                    type: 'array',
                    items: new OAT\Items(ref: MediaId::class),
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function index(Request $request, string $mediaId)
    {
        // 原本用 $request->user()->media()->find() 一次做「找得到」與「有權限」
        // 兩件事，但它只看 userables 綁定、不看來源是否免費——免費來源的影片
        // 在 captions 拿得到、在這裡卻拿不到。改為與其他端點共用同一份判斷。
        if (!$media = Media::find($mediaId)) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        if (!$media->isAccessibleBy($request->user())) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        // 一支影片可能有多筆摘要（使用者自己的 / 全站共用的、各語系各一筆），
        // 挑選順序見 Media::summaryFor()。只回已完成的：重跑摘要會先建一筆
        // text 還是 null 的資料列，回它等於把畫面上原本看得到的摘要清空。
        // 代價是這支影片第一次產摘要、還沒完成時回空陣列而不是 status=created。
        $summary = $media->summaryFor($request->user(), true);

        return $summary ? new SummaryResource($summary) : [];
    }

    #[OAT\Get(
        path: '/v1/media/{mediaId}/summaries/{summaryId}',
        operationId: 'api.v1.media.summaries.show',
        summary: 'Get a specific summary by ID',
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
            new OAT\Parameter(ref: SummaryId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Summary resource',
                content: new OAT\JsonContent(ref: SummarySchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    /**
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
     */
    public function show(Request $request, string $mediaId, string $summaryId)
    {
        // 原本用 $request->user()->media()->find() 一次做「找得到」與「有權限」
        // 兩件事，但它只看 userables 綁定、不看來源是否免費——免費來源的影片
        // 在 captions 拿得到、在這裡卻拿不到。改為與其他端點共用同一份判斷。
        if (!$media = Media::find($mediaId)) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        if (!$media->isAccessibleBy($request->user())) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        // 摘要分「全站共用」與「使用者自己的」，這裡必須把後者限制在本人——
        // 只綁 media 的話，任何拿得到這支影片的人都能用 ID 讀別人的專屬摘要。
        $summary = $media->summaries()
            ->where(function ($query) use ($request) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $request->user()->getKey());
            })
            ->find($summaryId);

        if (!$summary) {
            throw new NotFoundHttpException();
        }

        return new SummaryResource($summary);
    }

    #[OAT\Get(
        path: '/v1/media/{mediaId}/summaries/download',
        operationId: 'api.v1.media.summaries.download',
        summary: 'Download the summary as a document',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: MediaId::class),
            new OAT\Parameter(
                name: 'format',
                in: 'query',
                required: false,
                schema: new OAT\Schema(type: 'string', enum: SummaryExporter::FORMATS, default: 'md')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The summary document',
                content: new OAT\MediaType(mediaType: 'text/plain', schema: new OAT\Schema(type: 'string'))
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    /**
     * 匯出的是 index 挑出來的那一份（Media::summaryFor），不另外收 summaryId：
     * 使用者按下的下載鈕就在他當下看著的那份摘要旁邊。
     *
     * @throws InvalidRequestException
     */
    public function download(Request $request, string $mediaId): ResponseInterface
    {
        if (!$media = Media::find($mediaId)) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        if (!$media->isAccessibleBy($request->user())) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        // 先確認拿得到這支影片，再談方案：不然「沒有權限看」會被回成「請升級」。
        $this->assertDownloadEnabled($request);

        $format = strtolower(strval($request->input('format', SummaryExporter::FORMAT_MD)));

        if (!SummaryExporter::supports($format)) {
            throw new InvalidRequestException(
                ['format' => [__('validators.controllers.download.invalid_format')]]
            );
        }

        $summary = $media->summaryFor($request->user(), true);
        // getAttribute() 而不是 ->text：與 ResolvesUserPlan 一致，也讓靜態分析看得懂
        $text = $summary?->getAttribute('text');

        if (!$summary || !is_array($text)) {
            throw new InvalidRequestException(
                ['summary' => [__('validators.controllers.download.not_found')]]
            );
        }

        $title = strval($media->getAttribute('title'));

        $content = (new SummaryExporter())->render($text, $title, strval($media->getAttribute('url')), $format);

        $name = $this->safeFilename($title, strval($media->getKey()));
        $locale = trim(strval($summary->getAttribute('locale')));
        $suffix = $locale !== '' ? '.' . $locale : '';

        return $this->fileResponse(
            $content,
            "{$name}{$suffix}.{$format}",
            SummaryExporter::mimeFor($format)
        );
    }
}
