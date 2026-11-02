<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Carbon\Carbon;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Support\Facades\Http;

/**
 * YouTube 頻道／播放清單的 RSS（最新 15 筆）：抓取、解析、寫進 media。
 *
 * 新增來源的預覽、訂閱與 `sources:sync` 三處共用，確保三處看到的影片清單與建出來的
 * media 欄位一致。
 *
 * @phpstan-type RssEntry array{
 *   id: string,
 *   'yt:videoId': string,
 *   'yt:channelId': string,
 *   title: string,
 *   description: string,
 *   thumbnail: string,
 *   published: string,
 *   link: string,
 *   author: array{name: string, uri: string}
 * }
 */
class YoutubeRssFeed
{
    /** RSS 收進來的影片，`media.resource_id` 是 entry id：`yt:video:<videoId>`。 */
    public const string RESOURCE_ID_PREFIX = 'yt:video:';

    /**
     * 抓不到、非 2xx、不是 XML 都回 null，由呼叫端決定是略過還是擋下。
     *
     * @return null|array{title: string, entries: list<RssEntry>}
     */
    public function fetch(string $url): ?array
    {
        try {
            $response = Http::get($url);
        } catch (Exception) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            return null;
        }

        $namespaces = $xml->getNamespaces(true);
        $entries = [];

        foreach ($xml->entry as $entry) {
            $yt = $entry->children($namespaces['yt'] ?? '');
            $media = $entry->children($namespaces['media'] ?? '');

            $thumbnail = '';
            $description = '';
            $mediaGroup = $media->group ?? null;
            if ($mediaGroup) {
                $thumbAttr = $mediaGroup->thumbnail?->attributes();
                $thumbnail = (string) ($thumbAttr['url'] ?? '');
                $description = (string) ($mediaGroup->description ?? '');
            }

            $entries[] = [
                'id'           => (string) $entry->id,
                'yt:videoId'   => (string) ($yt->videoId ?? ''),
                'yt:channelId' => (string) ($yt->channelId ?? ''),
                'title'        => (string) $entry->title,
                'description'  => $description,
                'thumbnail'    => $thumbnail,
                'published'    => (string) $entry->published,
                'link'         => (string) ($entry->link['href'] ?? ''),
                'author'       => [
                    'name' => (string) ($entry->author->name ?? ''),
                    'uri'  => (string) ($entry->author->uri ?? ''),
                ],
            ];
        }

        return ['title' => (string) ($xml->title ?? 'No Title'), 'entries' => $entries];
    }

    /**
     * @return null|list<RssEntry>
     */
    public function fetchEntries(string $url): ?array
    {
        return $this->fetch($url)['entries'] ?? null;
    }

    /**
     * 以 entry id 取回或建立 media（新建的 `status = created`，由既有排程送去轉錄）。
     * 既有 media 還沒掛來源時補上 source_id。
     *
     * @param RssEntry $entry
     */
    public function upsertMedia(Source $source, array $entry): Media
    {
        $media = Media::firstOrCreate(
            ['resource_id' => $entry['id']],
            [
                'type'         => Media::TYPE_YOUTUBE,
                'source_id'    => $source->id,
                'title'        => $entry['title'],
                'description'  => $entry['description'],
                'duration'     => 0,
                'thumbnail'    => $entry['thumbnail'],
                'published_at' => Carbon::parse($entry['published']),
                'status'       => Media::STATUS_CREATED,
                'video_detail' => $entry,
                'audio_detail' => [],
            ]
        );

        if (!$media->wasRecentlyCreated && !$media->source_id) {
            $media->update(['source_id' => $source->id]);
        }

        return $media;
    }
}
