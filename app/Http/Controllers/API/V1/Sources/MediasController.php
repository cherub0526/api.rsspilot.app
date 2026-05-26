<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Sources;

use App\Models\Media;
use App\Models\Source;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Query;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Schemas\Paginators;
use App\Http\Resources\MediaResource;
use App\Exceptions\NotFoundHttpException;
use App\OpenApi\Parameters\Path\SourceId;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\MediaResource as MediaSchema;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class MediasController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/sources/{sourceId}/medias',
        operationId: 'api.v1.sources.medias.index',
        summary: 'List paginated media for a source',
        security: [['bearerAuth' => []]],
        tags: ['Sources'],
        parameters: [
            new OAT\Parameter(ref: SourceId::class),
            new OAT\Parameter(ref: Query\Limit::class),
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
                            items: new OAT\Items(ref: MediaSchema::class)
                        ),
                        new OAT\Property(property: 'links', ref: Paginators\Links::class),
                        new OAT\Property(property: 'meta', ref: Paginators\Meta::class),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    /**
     * @throws NotFoundHttpException
     */
    public function index(Request $request, string $sourceId): AnonymousResourceCollection
    {
        if (!Source::query()->where('id', $sourceId)->where('status', Source::STATUS_ACTIVE)->exists()) {
            throw new NotFoundHttpException();
        }

        $limit = min((int) ($request->query('limit', 12)), 50);

        $media = Media::query()
            ->where('source_id', $sourceId)
            ->orderByDesc('published_at')
            ->paginate($limit);

        return MediaResource::collection($media);
    }
}
