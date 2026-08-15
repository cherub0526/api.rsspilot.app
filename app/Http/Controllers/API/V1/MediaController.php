<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use Throwable;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Media;
use Hypervel\Http\Request;
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Path;
use App\Services\YoutubeService;
use App\OpenApi\Parameters\Query;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Responses\Http404;
use App\Validators\MediaValidator;
use App\OpenApi\Schemas\Paginators;
use App\Http\Resources\MediaResource;
use App\Services\SubscriptionService;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use App\Jobs\Media\VideoTranscriberStartJob;
use App\OpenApi\Schemas\MediaResource as MediaSchema;
use App\Services\VideoTranscriber\VideoTranscriberClient;
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
            new OAT\Parameter(ref: Query\Keyword::class),
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
        $params = $request->only(['type', 'range', 'limit', 'keyword']);
        $v = new MediaValidator($params);
        $v->setIndexRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $user = $request->user();
        $media = $user->media()
            ->with('source')
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
                    $query->where('media.published_at', '>=', $date);
                }
            })
            ->when($params['keyword'] ?? false, function ($query) use ($params) {
                $query->where('media.title', 'like', '%' . $params['keyword'] . '%')
                    ->orWhereHas('source', function ($q) use ($params) {
                        $q->where('title', 'like', '%' . $params['keyword'] . '%');
                    });
            })
            ->orderByDesc('media.published_at')
            ->paginate($params['limit'] ?? 12);

        return MediaResource::collection($media);
    }

    #[OAT\Post(
        path: '/v1/media',
        operationId: 'api.v1.media.store',
        summary: 'Add a single YouTube video by URL',
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\JsonContent(
                required: ['url'],
                properties: [
                    new OAT\Property(
                        property: 'url',
                        description: 'YouTube video URL (watch / youtu.be / shorts / embed)',
                        type: 'string',
                        format: 'url',
                        example: 'https://www.youtube.com/watch?v=uXHNRFHWDnM'
                    ),
                ]
            )
        ),
        tags: ['Media'],
        responses: [
            new OAT\Response(
                response: 201,
                description: 'Video added to the user library',
                content: new OAT\JsonContent(ref: MediaSchema::class)
            ),
            new OAT\Response(ref: Http400::class, response: 400),
            new OAT\Response(ref: Http401::class, response: 401),
        ]
    )]
    /**
     * 使用者貼一支 YouTube 影片網址把它加進自己的影片庫。
     *
     * @throws InvalidRequestException
     */
    public function store(
        Request $request,
        YoutubeService $youtubeService,
        VideoTranscriberClient $client,
        SubscriptionService $subscriptionService
    ): ResponseInterface {
        $params = $request->only(['url']);

        $v = new MediaValidator($params);
        $v->setStoreRules();

        if (!$v->passes()) {
            throw new InvalidRequestException($v->errors()->toArray());
        }

        $videoId = $youtubeService->getVideoIdFromUrl($params['url']);

        if ($videoId === null) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.media.invalid_url')]]);
        }

        $user = $request->user();

        // RSS 收進來的影片 resource_id 是 'yt:video:' 前綴（YoutubeService::getPlaylistItems），
        // 這裡必須組成同樣的格式，否則同一支影片會因為對不上而重複建立。
        $resourceId = 'yt:video:' . $videoId;
        $media = Media::where('resource_id', $resourceId)->first();
        $alreadyOwned = $media !== null
            && $user->media()->where('media.id', $media->getKey())->exists();

        // 已經在自己的影片庫裡就不佔額度——後面的 syncWithoutDetaching 不會新增 userables。
        if (!$alreadyOwned) {
            $this->assertVideoQuota($user, $subscriptionService);
        }

        $created = false;

        if ($media === null) {
            $media = $this->createMediaFromUrl($videoId, $resourceId, $youtubeService, $client);
            $created = true;
        }

        $user->media()->syncWithoutDetaching([$media->getKey()]);

        // 只有新建立的才送去轉錄。重用既有資料列代表它已經跑過或正在跑，
        // 再 dispatch 一次只是重複付轉錄費用。
        if ($created) {
            VideoTranscriberStartJob::dispatch($media);
        }

        return response()->json((new MediaResource($media))->resolve(), 201);
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
        $media = Media::with('source')->find($mediaId);

        if (!$media) {
            throw new NotFoundHttpException();
        }

        if (!$media->isAccessibleBy($request->user())) {
            throw new NotFoundHttpException();
        }

        return new MediaResource($media);
    }

    /**
     * 影片額度與 RSS 同步共用同一個池子：滾動 30 天內加進 userables 的筆數。
     * 分開計算的話，手動加入就成了繞過 RSS 上限的後門。
     *
     * @throws InvalidRequestException
     */
    private function assertVideoQuota(User $user, SubscriptionService $subscriptionService): void
    {
        $plan = $subscriptionService->getUserSubscriptionPlan(
            $subscriptionService->getUserSubscription((string) $user->getKey())
        );

        if ($plan === null || $plan->video_limit <= 0) {
            return;
        }

        $used = $user->media()
            ->whereBetween('userables.created_at', [now()->subDays(30)->startOfDay(), now()->endOfDay()])
            ->count();

        if ($used >= $plan->video_limit) {
            throw new InvalidRequestException(
                ['url' => [__('validators.controllers.media.video_limit_reached')]]
            );
        }
    }

    /**
     * 建立一筆手動加入的 media。
     *
     * 資料分兩個來源：getUrlInfo() 負責確認這支影片真的存在且可轉錄，順便給
     * 標題、縮圖、時長與頻道；它沒有的 description 與發布時間再由 YouTube Data
     * API 補。後者是 best-effort——配額用盡不該讓整個「新增影片」功能停擺。
     *
     * @throws InvalidRequestException 影片不存在或無法轉錄
     */
    private function createMediaFromUrl(
        string $videoId,
        string $resourceId,
        YoutubeService $youtubeService,
        VideoTranscriberClient $client
    ): Media {
        // 一律用正規化過的網址，不把使用者原本帶的追蹤參數送到外部服務。
        $url = 'https://www.youtube.com/watch?v=' . $videoId;

        try {
            $urlInfo = $client->getUrlInfo($url);
        } catch (Throwable) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.media.invalid_url')]]);
        }

        if (($urlInfo['code'] ?? null) !== 100000) {
            throw new InvalidRequestException(['url' => [__('validators.controllers.media.invalid_url')]]);
        }

        $data = $urlInfo['data'] ?? [];
        $videoInfo = $data['youtube_video_data']['videoInfo'] ?? [];

        $title = (string) ($data['title'] ?? $videoInfo['name'] ?? '');
        $thumbnail = (string) ($data['thumbnail_url']
            ?? $videoInfo['thumbnailUrl']['maxresdefault']
            ?? $videoInfo['thumbnailUrl']['hqdefault']
            ?? '');
        $duration = (int) ($data['audio_time'] ?? $videoInfo['duration'] ?? 0);
        $channelId = (string) ($videoInfo['channel_id'] ?? '');
        $channelTitle = (string) ($videoInfo['author'] ?? '');

        $snippet = $youtubeService->getVideoDetails($videoId)?->getSnippet();
        $description = (string) ($snippet?->getDescription() ?? '');
        $publishedAt = $snippet?->getPublishedAt();
        $publishedAt = $publishedAt ? Carbon::parse($publishedAt) : null;

        return Media::create([
            'type'         => Media::TYPE_YOUTUBE,
            'resource_id'  => $resourceId,
            'source_id'    => null,
            'title'        => $title,
            'description'  => $description,
            'duration'     => $duration,
            'thumbnail'    => $thumbnail,
            'published_at' => $publishedAt,
            'status'       => Media::STATUS_CREATED,
            // 下游一律從 video_detail['yt:videoId'] 取影片 ID（VideoTranscriberStartJob、
            // YoutubeCaptionJob、MediaResource），所以形狀必須跟 RSS 收進來的相容。
            'video_detail' => [
                'id'           => $resourceId,
                'yt:videoId'   => $videoId,
                'yt:channelId' => $channelId,
                'title'        => $title,
                'description'  => $description,
                'link'         => $url,
                'author'       => [
                    'name' => $channelTitle,
                    'uri'  => $channelId === '' ? '' : 'https://www.youtube.com/channel/' . $channelId,
                ],
                'published' => $publishedAt?->toIso8601String() ?? '',
                'updated'   => $publishedAt?->toIso8601String() ?? '',
                'media'     => [
                    'description' => $description,
                    'thumbnail'   => ['url' => $thumbnail],
                    'content'     => ['url' => ''],
                ],
            ],
            'audio_detail' => [],
        ]);
    }
}
