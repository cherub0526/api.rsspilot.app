<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Rss;
use App\Jobs\Rss\SyncJob;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\Services\YoutubeService;
use App\Validators\RSSValidator;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\HttpOk;
use App\Http\Resources\RSSResource;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\OpenApi\Schemas\RSSResource as RSSSchema;

class RSSController extends AbstractController
{
    /**
     * @throws InvalidRequestException
     */
    #[OAT\Get(
        path: '/v1/rss',
        operationId: 'api.v1.rss.index',
        summary: 'List RSS feed subscriptions',
        security: [['bearerAuth' => []]],
        tags: ['RSS'],
        parameters: [
            new OAT\Parameter(
                name: 'type',
                description: 'Type of RSS feed',
                in: 'query',
                required: true,
                schema: new OAT\Schema(
                    type: 'string',
                    enum: ['youtube']
                )
            ),
            new OAT\Parameter(
                name: 'limit',
                description: 'Number of items per page (1-10)',
                in: 'query',
                required: false,
                schema: new OAT\Schema(
                    type: 'integer',
                    maximum: 10,
                    minimum: 1
                )
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Successful operation',
                content: new OAT\JsonContent(
                    type: 'array',
                    items: new OAT\Items(ref: RSSSchema::class)
                )
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function index(Request $request)
    {
        $params = $request->only(['type', 'limit']);

        $v = new RSSValidator($params);
        $v->setIndexRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $rss = $request->user()->rss()->where('type', $params['type']);
        $limit = isset($params['limit']) ? intval($params['limit']) : 0;

        return RSSResource::collection($limit > 0 && $limit <= 10 ? $rss->paginate($limit) : $rss->get());
    }

    /**
     * @throws InvalidRequestException
     */
    #[OAT\Post(
        path: '/v1/rss',
        operationId: 'api.v1.rss.store',
        summary: 'Subscribe to RSS feed',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['type', 'url'],
                properties: [
                    new OAT\Property(
                        property: 'type',
                        description: 'Type of RSS feed',
                        type: 'string',
                        enum: ['youtube'],
                        example: 'youtube'
                    ),
                    new OAT\Property(
                        property: 'url',
                        description: 'YouTube channel URL or RSS feed URL',
                        type: 'string',
                        format: 'url',
                        example: 'https://www.youtube.com/@channelname'
                    ),
                ]
            )
        ),
        tags: ['RSS'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'RSS feed subscribed successfully',
                content: new OAT\JsonContent(ref: RSSSchema::class)
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function store(Request $request): RSSResource
    {
        $params = $request->only(['type', 'url']);

        $v = new RSSValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        if ($params['type'] === Rss::TYPE_YOUTUBE) {
            $channelId = (new YoutubeService())->getChannelIdFromUrl($params['url']);
            $params['url'] = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId;
        }

        $xml = @simplexml_load_file($params['url']);
        if ($xml === false) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.rss.invalid_url')]]);
        }

        if (!$rss = Rss::query()->where('url', $params['url'])->first()) {
            $rss = Rss::create([
                'type'    => $params['type'],
                'title'   => (string) ($xml->title ?? 'No Title'),
                'url'     => $params['url'],
                'comment' => '',
            ]);
        }

        if (!$rss->users()->find($request->user()->id)) {
            $rss->users()->attach($request->user()->id);
        }

        SyncJob::dispatch($rss);

        return new RSSResource($rss);
    }

    /**
     * @throws NotFoundHttpException
     */
    #[OAT\Delete(
        path: '/v1/rss/{rssId}',
        operationId: 'api.v1.rss.destroy',
        summary: 'Unsubscribe from RSS feed',
        security: [['bearerAuth' => []]],
        tags: ['RSS'],
        parameters: [
            new OAT\Parameter(
                name: 'rssId',
                description: 'RSS feed ID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(
                    type: 'string',
                    example: '01JCXYZ123456789ABCDEFGHIJ'
                )
            ),
        ],
        responses: [
            new OAT\Response(ref: HttpOk::class, response: 200),
            new OAT\Response(
                response: 404,
                description: 'RSS feed not found',
                content: new OAT\JsonContent(
                    properties: [
                        new OAT\Property(property: 'message', type: 'string', example: 'Resource not found'),
                    ]
                )
            ),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    public function destroy(Request $request, string $rssId): ResponseInterface
    {
        if (!$rss = $request->user()->rss()->find($rssId)) {
            throw new NotFoundHttpException();
        }

        $rss->users()->detach($request->user()->id);

        return response()->make(self::RESPONSE_OK);
    }
}
