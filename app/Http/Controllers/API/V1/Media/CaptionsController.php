<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Path;
use App\OpenApi\Responses\Http400;
use App\Http\Resources\CaptionResource;
use App\Exceptions\InvalidRequestException;
use App\OpenApi\Schemas\CaptionResource as CaptionSchema;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class CaptionsController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/captions',
        operationId: 'api.v1.media.captions.index',
        summary: 'List captions for a media',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: Path\MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            type: 'array',
                            items: new OAT\Items(
                                properties: [
                                    new OAT\Property(
                                        property: 'id',
                                        type: 'string',
                                        example: '01JCXYZ123456789ABCDEFGHIJ'
                                    ),
                                    new OAT\Property(property: 'locale', type: 'string', example: 'en'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request, string $mediaId): AnonymousResourceCollection
    {
        if (!$media = $request->user()->media()->find($mediaId)) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        $captions = $media->captions()->orderByDesc('primary')->get(['id', 'locale']);

        return CaptionResource::collection($captions);
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/captions/{captionId}',
        operationId: 'api.v1.media.captions.show',
        summary: 'Get caption details',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: Path\MediaId::class),
            new OAT\Parameter(ref: Path\CaptionId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(ref: CaptionSchema::class)
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request, string $mediaId, string $captionId): CaptionResource
    {
        if (!$media = $request->user()->media()->find($mediaId)) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        if (!$caption = $media->captions()->find($captionId)) {
            throw new InvalidRequestException(['caption' => [__('validators.controllers.media.caption_not_found')]]);
        }

        return new CaptionResource($caption);
    }
}
