<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Http\Resources\SummaryResource;
use App\OpenApi\Parameters\Path\MediaId;
use App\OpenApi\Parameters\Path\SummaryId;
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
        if (!$media = $request->user()->media()->find($mediaId)) {
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
    public function show(Request $request, string $mediaId, string $summaryId)
    {
    }
}
