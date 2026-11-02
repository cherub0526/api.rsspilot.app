<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Source;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Responses\HttpOk;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\OpenApi\Responses\Http422;
use App\Validators\SourceValidator;
use App\Http\Resources\SourceResource;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\OpenApi\Parameters\Path\SourceId;
use App\Exceptions\InvalidRequestException;
use App\Services\SourceSubscriptionService;
use App\Http\Controllers\AbstractController;
use App\Http\Resources\SourceDetailResource;
use App\OpenApi\Schemas\SourceResource as SourceSchema;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class SourcesController extends AbstractController
{
    #[OAT\Get(
        path: '/v1/sources',
        operationId: 'api.v1.sources.index',
        summary: 'List subscribed sources',
        security: [['bearerAuth' => []]],
        tags: ['Sources'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(
                            property: 'data',
                            type: 'array',
                            items: new OAT\Items(ref: SourceSchema::class)
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
        return SourceResource::collection(
            $request->user()->sources()->get()
        );
    }

    #[OAT\Post(
        path: '/v1/sources',
        operationId: 'api.v1.sources.store',
        summary: 'Subscribe to a YouTube channel or playlist',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['url', 'type'],
                properties: [
                    new OAT\Property(
                        property: 'url',
                        description: 'YouTube channel or playlist URL',
                        type: 'string',
                        format: 'url',
                        example: 'https://www.youtube.com/@googledevelopers'
                    ),
                    new OAT\Property(
                        property: 'type',
                        description: 'Source type',
                        type: 'string',
                        enum: ['channel', 'playlist'],
                        example: 'channel'
                    ),
                    new OAT\Property(
                        property: 'notify',
                        description: 'Enable email notification for new videos',
                        type: 'boolean',
                        example: true
                    ),
                    new OAT\Property(
                        property: 'video_ids',
                        description: 'Videos from the source\'s current RSS feed (GET /v1/sources/preview) to add to the library. '
                            . 'Defaults to none; only videos first seen after subscribing are added later.',
                        type: 'array',
                        items: new OAT\Items(type: 'string', pattern: '^[A-Za-z0-9_-]{11}$', example: 'dQw4w9WgXcQ'),
                        maxItems: 50
                    ),
                ]
            )
        ),
        tags: ['Sources'],
        responses: [
            new OAT\Response(
                response: 201,
                description: 'Source subscribed successfully',
                content: new OAT\JsonContent(ref: SourceSchema::class)
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http422::class, response: 422),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function store(Request $request): ResponseInterface
    {
        $params = $request->only(['url', 'type', 'notify', 'video_ids']);

        $v = new SourceValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = $request->user();
        $service = app(SourceSubscriptionService::class);

        $source = $service->subscribe(
            $user,
            $service->resolve($params['type'], $params['url']),
            (bool) ($params['notify'] ?? true),
            array_values($params['video_ids'] ?? [])
        );

        $attached = $user->sources()->find($source->id);

        return response()->json((new SourceResource($attached))->resolve(), 201);
    }

    #[OAT\Get(
        path: '/v1/sources/{sourceId}',
        operationId: 'api.v1.sources.show',
        summary: 'Get details of a single source',
        security: [['bearerAuth' => []]],
        tags: ['Sources'],
        parameters: [
            new OAT\Parameter(ref: SourceId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'id', type: 'string', example: '01JCXYZ123456789ABCDEFGHIJ'),
                        new OAT\Property(property: 'name', type: 'string', example: 'Google Developers'),
                        new OAT\Property(property: 'url', type: 'string', format: 'url', example: 'https://www.youtube.com/channel/UC295-Dw4tzbkl8M9I2GFRtg'),
                        new OAT\Property(property: 'type', type: 'string', enum: ['channel', 'playlist'], example: 'channel'),
                        new OAT\Property(property: 'notify', type: 'boolean', example: true),
                        new OAT\Property(property: 'thumbnail', type: 'string', nullable: true, example: 'https://yt3.ggpht.com/...'),
                        new OAT\Property(property: 'description', type: 'string', example: 'News and tutorials from the Google Developers team.'),
                        new OAT\Property(property: 'subscriber_count', type: 'integer', nullable: true, example: 1230000),
                    ],
                    type: 'object'
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    /**
     * @throws NotFoundHttpException
     */
    public function show(Request $request, string $sourceId): ResponseInterface
    {
        // 優先從使用者已訂閱來源載入（保留 pivot 資料）
        $source = $request->user()->sources()->find($sourceId);

        if (!$source) {
            // 退而確認是否為免費來源
            $source = Source::where('id', $sourceId)->where('free', true)->first();
        }

        if (!$source) {
            throw new NotFoundHttpException();
        }

        return response()->json((new SourceDetailResource($source))->resolve());
    }

    #[OAT\Put(
        path: '/v1/sources/{sourceId}',
        operationId: 'api.v1.sources.update',
        summary: 'Update notification setting for a subscribed source',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['notify'],
                properties: [
                    new OAT\Property(
                        property: 'notify',
                        description: 'Enable or disable email notification',
                        type: 'boolean',
                        example: false
                    ),
                ]
            )
        ),
        tags: ['Sources'],
        parameters: [
            new OAT\Parameter(ref: SourceId::class),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Source updated successfully',
                content: new OAT\JsonContent(ref: SourceSchema::class)
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    /**
     * @throws InvalidRequestException
     * @throws NotFoundHttpException
     */
    public function update(Request $request, string $sourceId): SourceResource
    {
        $params = $request->only(['notify']);

        $v = new SourceValidator($params);
        $v->setUpdateRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if (!$request->user()->sources()->find($sourceId)) {
            throw new NotFoundHttpException();
        }

        $request->user()->sources()->updateExistingPivot($sourceId, ['notify' => (bool) $params['notify']]);

        return new SourceResource(
            $request->user()->sources()->find($sourceId)
        );
    }

    #[OAT\Delete(
        path: '/v1/sources/{sourceId}',
        operationId: 'api.v1.sources.destroy',
        summary: 'Unsubscribe from a source',
        security: [['bearerAuth' => []]],
        tags: ['Sources'],
        parameters: [
            new OAT\Parameter(ref: SourceId::class),
        ],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(ref: Http401::class, response: 401),
            new OAT\Response(ref: Http404::class, response: 404),
        ]
    )]
    /**
     * @throws NotFoundHttpException
     */
    public function destroy(Request $request, string $sourceId): ResponseInterface
    {
        if (!$request->user()->sources()->find($sourceId)) {
            throw new NotFoundHttpException();
        }

        $request->user()->sources()->detach($sourceId);

        return response()->make(self::RESPONSE_OK);
    }
}
