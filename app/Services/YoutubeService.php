<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Google\Client;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\Caption;

class YoutubeService
{
    protected YouTube $youtube;

    public function __construct()
    {
        $client = new Client();
        $client->setDeveloperKey(env('YOUTUBE_API_KEY'));
        $this->youtube = new YouTube($client);
    }

    /**
     * Get channel ID from a YouTube channel URL.
     *
     * Supports:
     * - https://www.youtube.com/channel/UC...
     * - https://www.youtube.com/c/CustomName
     * - https://www.youtube.com/user/Username
     * - https://www.youtube.com/@Handle
     *
     * @return null|string Channel ID or null if not found
     */
    public function getChannelIdFromUrl(string $url): ?string
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['path'])) {
            return null;
        }

        $path = trim($parsedUrl['path'], '/');
        $segments = explode('/', $path);

        if (empty($segments)) {
            return null;
        }

        // Case 1: /channel/ID
        if ($segments[0] === 'channel' && isset($segments[1])) {
            return $segments[1];
        }

        // Case 2: /@Handle
        if (str_starts_with($segments[0], '@')) {
            return $this->getChannelIdByHandle($segments[0]);
        }

        // Case 3: /c/CustomName or /user/Username
        if (($segments[0] === 'c' || $segments[0] === 'user') && isset($segments[1])) {
            // For both custom URLs and legacy usernames, we can search
            // Note: 'user' might need channels->list with forUsername, but search is often more robust for mixed inputs
            // Let's try to resolve via search for custom URL or username
            return $this->searchChannelId($segments[1]);
        }

        // Case 4: Just a custom name at root (e.g. youtube.com/google) - treated as handle or custom URL
        // This is ambiguous, but we can try searching for it as a handle or query
        return $this->getChannelIdByHandle('@' . $segments[0]) ?? $this->searchChannelId($segments[0]);
    }

    /**
     * Get video details by ID.
     *
     * @return null|Video
     */
    public function getVideoDetails(string $videoId)
    {
        try {
            $response = $this->youtube->videos->listVideos('snippet,contentDetails,statistics', [
                'id' => $videoId,
            ]);

            $items = $response->getItems();
            if (!empty($items)) {
                return $items[0];
            }
        } catch (Exception $e) {
            // Log error or handle gracefully
        }

        return null;
    }

    /**
     * Get video captions.
     *
     * @return Caption[]
     */
    public function getVideoCaptions(string $videoId)
    {
        try {
            $response = $this->youtube->captions->listCaptions('snippet', $videoId);
            return $response->getItems();
        } catch (Exception $e) {
            // Log error or handle gracefully
        }

        return [];
    }

    /**
     * Get caption download URL.
     */
    public function getCaptionDownloadUrl(string $captionId): string
    {
        return 'https://www.googleapis.com/youtube/v3/captions/' . $captionId;
    }

    /**
     * Download caption content.
     *
     * @param string $format 'sbv', 'scc', 'srt', 'ttml', 'vtt'
     */
    public function downloadCaption(string $captionId, string $format = 'vtt'): ?string
    {
        try {
            $response = $this->youtube->captions->download($captionId, ['tfmt' => $format]);
            return (string) $response->getBody();
        } catch (Exception $e) {
            // Log error or handle gracefully
            return null;
        }
    }

    /**
     * Extract playlist ID from a YouTube playlist URL.
     * e.g. https://www.youtube.com/playlist?list=PLxxxxxxx.
     */
    public function getPlaylistIdFromUrl(string $url): ?string
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['query'])) {
            return null;
        }

        parse_str($parsedUrl['query'], $queryParams);
        $listId = $queryParams['list'] ?? null;

        return ($listId && strlen($listId) > 2) ? $listId : null;
    }

    /**
     * Extract a video ID from a YouTube video URL.
     *
     * 支援四種形式：
     * - https://www.youtube.com/watch?v=VIDEOID
     * - https://youtu.be/VIDEOID
     * - https://www.youtube.com/shorts/VIDEOID
     * - https://www.youtube.com/embed/VIDEOID
     *
     * 純粹解析字串，不呼叫任何 API——格式不對就不必白跑一趟外部服務。
     * 影片是否真的存在由呼叫端另外確認。
     */
    public function getVideoIdFromUrl(string $url): ?string
    {
        $parsedUrl = parse_url($url);

        if (!is_array($parsedUrl) || !isset($parsedUrl['host'])) {
            return null;
        }

        $host = strtolower($parsedUrl['host']);
        $path = trim($parsedUrl['path'] ?? '', '/');
        $segments = $path === '' ? [] : explode('/', $path);

        // youtu.be/VIDEOID —— 短網址把 ID 放在路徑第一段
        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            return $this->normaliseVideoId($segments[0] ?? null);
        }

        // 只認 youtube.com 本身與它的子網域。單純比對結尾會把 evilyoutube.com
        // 也放進來。
        if ($host !== 'youtube.com' && !str_ends_with($host, '.youtube.com')) {
            return null;
        }

        if (in_array($segments[0] ?? '', ['shorts', 'embed', 'live', 'v'], true)) {
            return $this->normaliseVideoId($segments[1] ?? null);
        }

        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);

            return $this->normaliseVideoId($queryParams['v'] ?? null);
        }

        return null;
    }

    /**
     * Fetch playlist metadata: title, channel_id, channel_title, thumbnail.
     *
     * @return null|array{title: string, channel_id: string, channel_title: string, thumbnail: null|string}
     */
    public function getPlaylistDetails(string $playlistId): ?array
    {
        try {
            $response = $this->youtube->playlists->listPlaylists('snippet', [
                'id' => $playlistId,
            ]);

            $items = $response->getItems();
            if (empty($items)) {
                return null;
            }

            $snippet = $items[0]->getSnippet();
            $thumbnails = $snippet->getThumbnails();

            return [
                'title'         => $snippet->getTitle() ?? '',
                'channel_id'    => $snippet->getChannelId() ?? '',
                'channel_title' => $snippet->getChannelTitle() ?? '',
                'thumbnail'     => $thumbnails?->getHigh()?->getUrl()
                    ?? $thumbnails?->getMedium()?->getUrl()
                    ?? $thumbnails?->getDefault()?->getUrl(),
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Fetch channel statistics (subscriber_count, video_count) via YouTube Data API.
     *
     * @return null|array{subscriber_count: int, video_count: int}
     */
    public function getChannelStatistics(string $channelId): ?array
    {
        try {
            $response = $this->youtube->channels->listChannels('statistics', ['id' => $channelId]);
            $items = $response->getItems();
            if (empty($items)) {
                return null;
            }
            $stats = $items[0]->getStatistics();

            return [
                'subscriber_count' => (int) $stats->getSubscriberCount(),
                'video_count'      => (int) $stats->getVideoCount(),
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Fetch the channel avatar/thumbnail URL via YouTube Data API.
     */
    public function getChannelThumbnail(string $channelId): ?string
    {
        try {
            $response = $this->youtube->channels->listChannels('snippet', ['id' => $channelId]);
            $items = $response->getItems();
            if (empty($items)) {
                return null;
            }
            $thumbnails = $items[0]->getSnippet()->getThumbnails();

            return $thumbnails?->getHigh()?->getUrl()
                ?? $thumbnails?->getMedium()?->getUrl()
                ?? $thumbnails?->getDefault()?->getUrl();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Fetch all items in a playlist via YouTube Data API (handles pagination).
     * Returns data shaped to match the RSS-parsed format expected by SyncJob.
     */
    public function getPlaylistItems(string $playlistId): array
    {
        $items = [];
        $pageToken = null;

        do {
            try {
                $params = [
                    'playlistId' => $playlistId,
                    'maxResults' => 50,
                ];
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $response = $this->youtube->playlistItems->listPlaylistItems('snippet', $params);

                foreach ($response->getItems() as $item) {
                    $snippet = $item->getSnippet();
                    $resourceId = $snippet->getResourceId();

                    if ($resourceId->getKind() !== 'youtube#video') {
                        continue;
                    }

                    $videoId = $resourceId->getVideoId();
                    $channelId = $snippet->getVideoOwnerChannelId() ?? $snippet->getChannelId() ?? '';
                    $channelTitle = $snippet->getVideoOwnerChannelTitle() ?? $snippet->getChannelTitle() ?? '';
                    $description = $snippet->getDescription() ?? '';
                    $publishedAt = $snippet->getPublishedAt() ?? '';

                    $thumbnails = $snippet->getThumbnails();
                    $thumbnail = $thumbnails?->getHigh()?->getUrl()
                        ?? $thumbnails?->getMedium()?->getUrl()
                        ?? $thumbnails?->getDefault()?->getUrl()
                        ?? '';

                    $items[] = [
                        'id'           => 'yt:video:' . $videoId,
                        'yt:videoId'   => $videoId,
                        'yt:channelId' => $channelId,
                        'title'        => $snippet->getTitle() ?? '',
                        'description'  => $description,
                        'link'         => 'https://www.youtube.com/watch?v=' . $videoId,
                        'author'       => [
                            'name' => $channelTitle,
                            'uri'  => 'https://www.youtube.com/channel/' . $channelId,
                        ],
                        'published' => $publishedAt,
                        'updated'   => $publishedAt,
                        'media'     => [
                            'description' => $description,
                            'thumbnail'   => ['url' => $thumbnail],
                            'content'     => ['url' => ''],
                            'community'   => [
                                'statistics' => ['views' => '0'],
                                'starRating' => ['average' => '0', 'max' => '5', 'min' => '1', 'count' => '0'],
                            ],
                        ],
                    ];
                }

                $pageToken = $response->getNextPageToken();
            } catch (Exception $e) {
                break;
            }
        } while ($pageToken);

        return $items;
    }

    /**
     * YouTube 的 video ID 固定是 11 個 [A-Za-z0-9_-] 字元。卡死長度與字元集，
     * 才不會讓 /watch?v= 後面隨便接的字串被當成合法 ID 一路送到外部服務。
     */
    protected function normaliseVideoId(?string $candidate): ?string
    {
        if (!is_string($candidate)) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) === 1 ? $candidate : null;
    }

    protected function getChannelIdByHandle(string $handle): ?string
    {
        try {
            $response = $this->youtube->channels->listChannels('id', ['forHandle' => $handle]);
            $items = $response->getItems();
            if (!empty($items)) {
                return $items[0]->getId();
            }
        } catch (Exception $e) {
            // Log error or handle gracefully
        }

        return null;
    }

    protected function searchChannelId(string $query): ?string
    {
        try {
            $response = $this->youtube->search->listSearch('snippet', [
                'q'          => $query,
                'type'       => 'channel',
                'maxResults' => 1,
            ]);

            $items = $response->getItems();
            if (!empty($items)) {
                return $items[0]->getSnippet()->getChannelId();
            }
        } catch (Exception $e) {
            // Log error or handle gracefully
        }

        return null;
    }
}
