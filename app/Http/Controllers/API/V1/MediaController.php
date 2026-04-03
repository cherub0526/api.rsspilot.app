<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Path;
use App\OpenApi\Parameters\Query;
use App\Validators\MediaValidator;
use App\Http\Resources\MediaResource;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class MediaController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Get(
        path: '/api/v1/media',
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
                            items: new OAT\Items(ref: MediaResource::class)
                        ),
                        new OAT\Property(
                            property: 'links',
                            properties: [
                                new OAT\Property(
                                    property: 'first',
                                    type: 'string',
                                    example: 'http://localhost/api/v1/media?page=1'
                                ),
                                new OAT\Property(
                                    property: 'last',
                                    type: 'string',
                                    example: 'http://localhost/api/v1/media?page=5'
                                ),
                                new OAT\Property(property: 'prev', type: 'string', example: null, nullable: true),
                                new OAT\Property(
                                    property: 'next',
                                    type: 'string',
                                    example: 'http://localhost/api/v1/media?page=2',
                                    nullable: true
                                ),
                            ],
                            type: 'object'
                        ),
                        new OAT\Property(
                            property: 'meta',
                            properties: [
                                new OAT\Property(property: 'current_page', type: 'integer', example: 1),
                                new OAT\Property(property: 'per_page', type: 'integer', example: 12),
                                new OAT\Property(property: 'total', type: 'integer', example: 50),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OAT\Response(
                response: 400,
                description: 'Invalid request parameters',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['type' => ['The type field is required.']]
                        ),
                    ]
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
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

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Get(
        path: '/api/v1/media/{mediaId}',
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
                content: new OAT\JsonContent(ref: MediaResource::class)
            ),
            new OAT\Response(
                response: 400,
                description: 'Media not found',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['media' => ['Media not found.']]
                        ),
                    ]
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request, string $mediaId): MediaResource
    {
        if (!$media = $request->user()->media()->find($mediaId)) {
            throw new InvalidRequestException(['media' => [__('validators.controllers.media.not_found')]]);
        }

        return new MediaResource($media);
    }
}
