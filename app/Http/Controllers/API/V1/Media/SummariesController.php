<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use App\Models\Media;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Http\Resources\SummaryResource;
use App\OpenApi\Parameters\Path\MediaId;
use App\OpenApi\Parameters\Path\SummaryId;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\OpenApi\Schemas\SummaryResource as SummarySchema;

class SummariesController
{
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

        $summary = $media->summaries()->first();

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

        if (!$summary = $media->summaries()->find($summaryId)) {
            throw new NotFoundHttpException();
        }

        return new SummaryResource($summary);
    }
}
