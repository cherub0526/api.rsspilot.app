<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V1;

use App\Models\Source;
use Hypervel\Http\Request;
use App\Services\YoutubeService;
use Hypervel\Support\Facades\Http;
use Hypervel\HttpClient\ConnectionException;
use App\Validators\SourceValidator;
use App\Http\Resources\SourceResource;
use Psr\Http\Message\ResponseInterface;
use App\Exceptions\NotFoundHttpException;
use App\Exceptions\InvalidRequestException;
use App\Http\Controllers\AbstractController;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;

class SourcesController extends AbstractController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return SourceResource::collection(
            $request->user()->sources()->get()
        );
    }

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

        $notify         = (bool) ($params['notify'] ?? true);
        $youtubeService = app(YoutubeService::class);

        if ($params['type'] === 'channel') {
            [$externalId, $title, $dbType] = $this->resolveChannel($params['url'], $youtubeService);
        } else {
            [$externalId, $title, $dbType] = $this->resolvePlaylist($params['url'], $youtubeService);
        }

        $source = Source::firstOrCreate(
            ['external_id' => $externalId, 'type' => $dbType],
            ['title' => $title, 'url' => $this->buildRssUrl($dbType, $externalId), 'status' => Source::STATUS_ACTIVE]
        );

        if ($request->user()->sources()->find($source->id)) {
            $request->user()->sources()->updateExistingPivot($source->id, ['notify' => $notify]);
        } else {
            $request->user()->sources()->attach($source->id, ['notify' => $notify]);
        }

        $attached = $request->user()->sources()->find($source->id);

        return response()->json((new SourceResource($attached))->resolve(), 201);
    }

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
     * @return array{0: string, 1: string, 2: string}
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

        return [$channelId, (string) ($xml->title ?? 'No Title'), Source::TYPE_YOUTUBE_CHANNEL];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
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

        return [$playlistId, $details['title'], Source::TYPE_YOUTUBE_PLAYLIST];
    }

    private function buildRssUrl(string $dbType, string $externalId): string
    {
        if ($dbType === Source::TYPE_YOUTUBE_CHANNEL) {
            return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $externalId;
        }

        return 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $externalId;
    }
}
