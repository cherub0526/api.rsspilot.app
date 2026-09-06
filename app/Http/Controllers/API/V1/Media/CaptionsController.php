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
use Psr\Http\Message\ResponseInterface;
use App\Services\Export\CaptionExporter;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\Concerns\SendsDownloads;
use App\Http\Controllers\Concerns\ResolvesUserPlan;
use App\OpenApi\Schemas\CaptionResource as CaptionSchema;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class CaptionsController
{
    use ResolvesUserPlan;
    use SendsDownloads;

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

    /**
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
     */
    #[OAT\Get(
        path: '/v1/media/{mediaId}/captions/{captionId}/download',
        operationId: 'api.v1.media.captions.download',
        summary: 'Download a caption as a subtitle file',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: Path\MediaId::class),
            new OAT\Parameter(ref: Path\CaptionId::class),
            new OAT\Parameter(
                name: 'format',
                in: 'query',
                required: false,
                schema: new OAT\Schema(type: 'string', enum: CaptionExporter::FORMATS, default: 'srt')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The subtitle file',
                content: new OAT\MediaType(mediaType: 'text/plain', schema: new OAT\Schema(type: 'string'))
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function download(Request $request, string $mediaId, string $captionId): ResponseInterface
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

        // 先確認拿得到這支影片，再談方案：不然「沒有權限看」會被回成「請升級」。
        $this->assertDownloadEnabled($request);

        $format = strtolower(strval($request->input('format', CaptionExporter::FORMAT_SRT)));

        if (!CaptionExporter::supports($format)) {
            throw new InvalidRequestException(
                ['format' => [__('validators.controllers.download.invalid_format')]]
            );
        }

        if (!$caption = $media->captions()->find($captionId)) {
            throw new NotFoundHttpException();
        }

        // getAttribute() 而不是 ->segments：與 ResolvesUserPlan 一致，也讓靜態分析看得懂
        $content = (new CaptionExporter())->render($caption->getAttribute('segments') ?? [], $format);

        if (trim($content) === '') {
            throw new InvalidRequestException(
                ['caption' => [__('validators.controllers.download.not_found')]]
            );
        }

        $name = $this->safeFilename(strval($media->getAttribute('title')), strval($media->getKey()));
        $locale = trim(strval($caption->getAttribute('locale')));
        $suffix = $locale !== '' ? '.' . $locale : '';

        return $this->fileResponse(
            $content,
            "{$name}{$suffix}.{$format}",
            CaptionExporter::mimeFor($format)
        );
    }
}
