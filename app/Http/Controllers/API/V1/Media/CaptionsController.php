<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Media;

use App\Models\Media;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Path;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\Http\Resources\CaptionResource;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\OpenApi\Schemas\CaptionResource as CaptionSchema;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class CaptionsController
{
    /**
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
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
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request, string $mediaId): AnonymousResourceCollection
    {
        if (!$media = Media::find($mediaId)) {
            throw new NotFoundHttpException();
        }

        // 存取權限一律以 media 為準（Media::isAccessibleBy）：使用者可以不訂閱
        // 任何來源、直接把單支影片加進自己的影片庫，那種 media 在 user_sources
        // 裡沒有對應的列，用來源訂閱判斷會誤判成無權存取。
        if (!$media->isAccessibleBy($request->user())) {
            throw new NotFoundHttpException();
        }

        $captions = $media->captions()->orderByDesc('primary')->get(['id', 'locale']);

        return CaptionResource::collection($captions);
    }

    /**
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
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
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function show(Request $request, string $mediaId, string $captionId): CaptionResource
    {
        if (!$media = Media::find($mediaId)) {
            throw new NotFoundHttpException();
        }

        // 存取權限一律以 media 為準（Media::isAccessibleBy）：使用者可以不訂閱
        // 任何來源、直接把單支影片加進自己的影片庫，那種 media 在 user_sources
        // 裡沒有對應的列，用來源訂閱判斷會誤判成無權存取。
        if (!$media->isAccessibleBy($request->user())) {
            throw new NotFoundHttpException();
        }

        if (!$caption = $media->captions()->find($captionId)) {
            throw new NotFoundHttpException();
        }

        return new CaptionResource($caption);
    }
}
