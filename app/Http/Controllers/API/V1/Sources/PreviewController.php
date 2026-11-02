<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1\Sources;

use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http422;
use App\Validators\SourceValidator;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\InvalidRequestException;
use App\Services\SourceSubscriptionService;
use App\Http\Controllers\AbstractController;

class PreviewController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/sources/preview',
        operationId: 'api.v1.sources.preview.index',
        summary: 'Preview the videos currently in a channel or playlist RSS feed before subscribing',
        security: [['bearerAuth' => []]],
        tags: ['Sources'],
        parameters: [
            new OAT\Parameter(
                name: 'url',
                description: 'YouTube channel or playlist URL',
                in: 'query',
                required: true,
                schema: new OAT\Schema(type: 'string', example: 'https://www.youtube.com/@googledevelopers')
            ),
            new OAT\Parameter(
                name: 'type',
                description: 'Source type',
                in: 'query',
                required: true,
                schema: new OAT\Schema(type: 'string', enum: ['channel', 'playlist'], example: 'channel')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Source info, its RSS videos (newest first) and the remaining 30-day video quota',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'source',
                            properties: [
                                new OAT\Property(property: 'title', type: 'string', example: 'Google Developers'),
                                new OAT\Property(property: 'type', type: 'string', enum: ['channel', 'playlist'], example: 'channel'),
                                new OAT\Property(property: 'thumbnail', type: 'string', nullable: true, example: 'https://yt3.ggpht.com/...'),
                            ],
                            type: 'object'
                        ),
                        new OAT\Property(
                            property: 'videos',
                            type: 'array',
                            items: new OAT\Items(
                                properties: [
                                    new OAT\Property(property: 'video_id', type: 'string', example: 'dQw4w9WgXcQ'),
                                    new OAT\Property(property: 'title', type: 'string', example: 'EP710'),
                                    new OAT\Property(property: 'thumbnail', type: 'string', example: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'),
                                    new OAT\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true, example: '2026-11-01T10:00:00+00:00'),
                                    new OAT\Property(property: 'in_library', description: 'Already in the user\'s library', type: 'boolean', example: false),
                                ],
                                type: 'object'
                            )
                        ),
                        new OAT\Property(
                            property: 'quota',
                            description: 'Both null when the plan has no video limit',
                            properties: [
                                new OAT\Property(property: 'limit', type: 'integer', nullable: true, example: 30),
                                new OAT\Property(property: 'remaining', type: 'integer', nullable: true, example: 12),
                            ],
                            type: 'object'
                        ),
                    ],
                    type: 'object'
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http422::class, response: 422),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function index(Request $request): ResponseInterface
    {
        $params = $request->only(['url', 'type']);

        $v = (new SourceValidator($params))->setPreviewRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $service = app(SourceSubscriptionService::class);
        $resolved = $service->resolve($params['type'], $params['url']);

        return response()->json($service->preview($request->user(), $resolved));
    }
}
