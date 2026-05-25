<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Media;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Path;
use App\OpenApi\Parameters\Query;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\Validators\MediaValidator;
use App\OpenApi\Schemas\Paginators;
use App\Http\Resources\MediaResource;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\MediaResource as MediaSchema;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class MediaController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Get(
        path: '/v1/media',
        operationId: 'api.v1.media.index',
        summary: 'List user media',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: Query\Type::class),
            new OAT\Parameter(ref: Query\Range::class),
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
                        new OAT\Property(
                            property: 'links',
                            ref: Paginators\Links::class
                        ),
                        new OAT\Property(
                            property: 'meta',
                            ref: Paginators\Meta::class
                        ),
                    ]
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $params = $request->only(['type', 'range', 'limit']);
        $v = new MediaValidator($params);
        $v->setIndexRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $media = $request->user()->media()
            ->where('type', $params['type'])
            ->when($params['range'] ?? false, function ($query) use ($params) {
                $date = match ($params['range']) {
                    'today' => now()->startOfDay(),
                    'week'  => now()->subWeek()->startOfDay(),
                    'month' => now()->subMonth()->startOfDay(),
                    'year'  => now()->subYear()->startOfDay(),
                    default => null,
                };
                if ($date) {
                    $query->where('published_at', '>=', $date);
                }
            })
            ->orderByDesc('published_at')
            ->paginate(
                $params['limit'] ?? 12
            );

        return MediaResource::collection($media);
    }

    #[OAT\Get(
        path: '/v1/media/{mediaId}',
        operationId: 'api.v1.media.show',
        summary: 'Get media details',
        security: [['bearerAuth' => []]],
        tags: ['Media'],
        parameters: [
            new OAT\Parameter(ref: Path\MediaId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(ref: MediaSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    /**
     * @throws NotFoundHttpException
     */
    public function show(Request $request, string $mediaId): MediaResource
    {
        $media = Media::find($mediaId);

        if (!$media) {
            throw new NotFoundHttpException();
        }

        $source = $media->source;

        $hasAccess = ($source?->free ?? false)
            || ($source && $request->user()->sources()->where('sources.id', $source->getKey())->exists());

        if (!$hasAccess) {
            throw new NotFoundHttpException();
        }

        return new MediaResource($media);
    }
}
