<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Source;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\Services\YoutubeService;
use App\OpenApi\Responses\HttpOk;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use Hypervel\Support\Facades\Http;
use App\Validators\SourceValidator;
use App\Services\SubscriptionService;
use App\Http\Resources\SourceResource;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\OpenApi\Parameters\Path\SourceId;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Http\Resources\SourceDetailResource;
use Hypervel\HttpClient\ConnectionException;
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
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    /**
     * @throws InvalidRequestException
     */
    public function store(Request $request): ResponseInterface
    {
        $params = $request->only(['url', 'type', 'notify']);

        $v = new SourceValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $notify = (bool) ($params['notify'] ?? true);
        $youtubeService = app(YoutubeService::class);

        if ($params['type'] === 'channel') {
            [$externalId, $title, $dbType, $thumbnail] = $this->resolveChannel($params['url'], $youtubeService);
        } else {
            [$externalId, $title, $dbType, $thumbnail] = $this->resolvePlaylist($params['url'], $youtubeService);
        }

        $source = Source::firstOrCreate(
            ['external_id' => $externalId, 'type' => $dbType],
            ['title' => $title, 'url' => $this->buildRssUrl($dbType, $externalId), 'thumbnail' => $thumbnail, 'status' => Source::STATUS_ACTIVE]
        );

        if ($dbType === Source::TYPE_YOUTUBE_CHANNEL && empty($source->metadata['subscriber_count'])) {
            $stats = $youtubeService->getChannelStatistics($externalId);
            if ($stats) {
                $source->metadata = array_merge($source->metadata ?? [], $stats);
                $source->save();
            }
        }

        $user = $request->user();
        $alreadySubscribed = (bool) $user->sources()->find($source->id);

        if (!$alreadySubscribed) {
            $subscriptionService = app(SubscriptionService::class);
            $plan = $subscriptionService->getUserSubscriptionPlan(
                $subscriptionService->getUserSubscription($user->id)
            );

            if ($plan !== null && $plan->channel_limit > 0 && $user->sources()->count() >= $plan->channel_limit) {
                throw new InvalidRequestException(
                    ['source' => [__('validators.controllers.sources.channel_limit_reached')]]
                );
            }
        }

        if ($alreadySubscribed) {
            $user->sources()->updateExistingPivot($source->id, ['notify' => $notify]);
        } else {
            $user->sources()->attach($source->id, ['notify' => $notify]);
        }

        // Sync existing media from this source into userables so the 30-day
        // quota is tracked correctly. Records are only added, never removed,
        // preventing the add-then-delete exploit.
        app(SubscriptionService::class)->syncSourceMediaToUserables($user, $source);

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

    /**
     * @return array{0: string, 1: string, 2: string, 3: null|string}
     * @throws InvalidRequestException
     */
    private function resolveChannel(string $url, YoutubeService $youtubeService): array
    {
        $channelId = $youtubeService->getChannelIdFromUrl($url);

        if (!$channelId) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        $rssUrl = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId;

        try {
            $response = Http::get($rssUrl);
        } catch (ConnectionException) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        if (!$response->successful()) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        $thumbnail = $youtubeService->getChannelThumbnail($channelId);

        return [$channelId, (string) ($xml->title ?? 'No Title'), Source::TYPE_YOUTUBE_CHANNEL, $thumbnail];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: null|string}
     * @throws InvalidRequestException
     */
    private function resolvePlaylist(string $url, YoutubeService $youtubeService): array
    {
        $playlistId = $youtubeService->getPlaylistIdFromUrl($url);

        if (!$playlistId) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        $details = $youtubeService->getPlaylistDetails($playlistId);

        if (!$details) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
        }

        return [$playlistId, $details['title'], Source::TYPE_YOUTUBE_PLAYLIST, $details['thumbnail']];
    }

    private function buildRssUrl(string $dbType, string $externalId): string
    {
        if ($dbType === Source::TYPE_YOUTUBE_CHANNEL) {
            return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $externalId;
        }

        return 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $externalId;
    }
}
