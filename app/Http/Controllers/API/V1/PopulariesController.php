<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Source;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\Http\Controllers\AbstractController;
use App\Http\Resources\PopularSourceResource;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class PopulariesController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/popularies',
        operationId: 'api.v1.popularies.index',
        summary: 'List all free sources with video counts',
        security: [['bearerAuth' => []]],
        tags: ['Popularies'],
        parameters: [
            new OAT\Parameter(
                name: 'type',
                description: 'Filter by source type',
                in: 'query',
                required: false,
                schema: new OAT\Schema(type: 'string', enum: ['channel', 'playlist'])
            ),
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
                                    new OAT\Property(property: 'id', type: 'string'),
                                    new OAT\Property(property: 'type', type: 'string', enum: ['channel', 'playlist']),
                                    new OAT\Property(property: 'name', type: 'string'),
                                    new OAT\Property(property: 'desc', type: 'string'),
                                    new OAT\Property(property: 'thumbnail', type: 'string', nullable: true),
                                    new OAT\Property(property: 'video_count', type: 'integer'),
                                    new OAT\Property(
                                        property: 'subscriber_count',
                                        type: 'string',
                                        example: '12.4K',
                                        nullable: true
                                    ),
                                ],
                                type: 'object'
                            )
                        ),
                    ],
                    type: 'object'
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $typeMap = [
            'channel'  => Source::TYPE_YOUTUBE_CHANNEL,
            'playlist' => Source::TYPE_YOUTUBE_PLAYLIST,
        ];

        $query = Source::query()
            ->where('free', true)
            ->withCount('media');

        $type = $request->query('type');
        if (isset($typeMap[$type])) {
            $query->where('type', $typeMap[$type]);
        }

        $sources = $query->get();

        return PopularSourceResource::collection($sources);
    }
}
