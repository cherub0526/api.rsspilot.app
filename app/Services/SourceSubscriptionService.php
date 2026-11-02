<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use App\Models\UserSource;
use Carbon\CarbonInterface;
use Hypervel\Support\Facades\DB;
use App\Exceptions\InvalidRequestException;

/**
 * 訂閱來源與影片庫之間的規則：訂閱時只收使用者勾選的影片，之後只收同步時間點
 * （`user_sources.synced_at`）之後系統才第一次看到的影片。
 * 見 docs/lore/media/business-rules.md〈訂閱只收同步時間點之後的影片〉。
 *
 * @phpstan-import-type RssEntry from YoutubeRssFeed
 *
 * @phpstan-type ResolvedSource array{
 *   type: string,
 *   external_id: string,
 *   title: string,
 *   thumbnail: null|string,
 *   entries: list<RssEntry>
 * }
 */
class SourceSubscriptionService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly SourceService $sourceService,
        private readonly YoutubeRssFeed $rssFeed
    ) {
    }

    /**
     * 把使用者貼的頻道／播放清單網址解析成來源資訊與目前的 RSS 清單，不寫資料庫。
     *
     * 只接受 YouTube 頻道與播放清單網址：抓的永遠是由解析出的 ID 組出來的
     * youtube.com RSS 網址，不是使用者給的網址（避免 SSRF）。
     *
     * @param string $type `channel` | `playlist`
     * @return ResolvedSource
     * @throws InvalidRequestException 網址無法解析或 RSS 抓不到
     */
    public function resolve(string $type, string $url): array
    {
        // YoutubeService 在測試裡會被換成 mock；這個 service 由容器快取，所以每次現取。
        $youtubeService = app(YoutubeService::class);

        if ($type === 'channel') {
            $externalId = $youtubeService->getChannelIdFromUrl($url) ?? $this->invalidUrl();
            $feed = $this->rssFeed->fetch($this->sourceService->buildRssUrl(Source::TYPE_YOUTUBE_CHANNEL, $externalId))
                ?? $this->invalidUrl();

            return [
                'type'        => Source::TYPE_YOUTUBE_CHANNEL,
                'external_id' => $externalId,
                'title'       => $feed['title'],
                'thumbnail'   => $youtubeService->getChannelThumbnail($externalId),
                'entries'     => $feed['entries'],
            ];
        }

        $externalId = $youtubeService->getPlaylistIdFromUrl($url) ?? $this->invalidUrl();
        $details = $youtubeService->getPlaylistDetails($externalId) ?? $this->invalidUrl();
        $entries = $this->rssFeed->fetchEntries($this->sourceService->buildRssUrl(Source::TYPE_YOUTUBE_PLAYLIST, $externalId))
            ?? $this->invalidUrl();

        return [
            'type'        => Source::TYPE_YOUTUBE_PLAYLIST,
            'external_id' => $externalId,
            'title'       => $details['title'],
            'thumbnail'   => $details['thumbnail'],
            'entries'     => $entries,
        ];
    }

    /**
     * 新增來源時的預覽：RSS 影片清單（新到舊）、哪些已在影片庫、剩餘影片額度。
     *
     * @param ResolvedSource $resolved
     * @return array{
     *   source: array{title: string, type: string, thumbnail: null|string},
     *   videos: list<array{video_id: string, title: string, thumbnail: string, published_at: null|string, in_library: bool}>,
     *   quota: array{limit: null|int, remaining: null|int}
     * }
     */
    public function preview(User $user, array $resolved): array
    {
        $entries = $resolved['entries'];
        $owned = $user->media()
            ->whereIn('media.resource_id', array_column($entries, 'id'))
            ->pluck('media.resource_id')
            ->all();

        usort($entries, fn (array $a, array $b) => ($this->publishedAt($b)?->getTimestamp() ?? 0)
            <=> ($this->publishedAt($a)?->getTimestamp() ?? 0));

        $plan = $this->subscriptionService->getUserSubscriptionPlan(
            $this->subscriptionService->getUserSubscription($user->id)
        );

        return [
            'source' => [
                'title'     => $resolved['title'],
                'type'      => $resolved['type'] === Source::TYPE_YOUTUBE_CHANNEL ? 'channel' : 'playlist',
                'thumbnail' => $resolved['thumbnail'],
            ],
            'videos' => array_map(fn (array $entry) => [
                'video_id'     => $entry['yt:videoId'],
                'title'        => $entry['title'],
                'thumbnail'    => $entry['thumbnail'],
                'published_at' => $this->publishedAt($entry)?->toIso8601String(),
                'in_library'   => in_array($entry['id'], $owned, true),
            ], $entries),
            'quota' => [
                'limit'     => $plan !== null && $plan->video_limit > 0 ? (int) $plan->video_limit : null,
                'remaining' => $this->subscriptionService->remainingVideoQuota($user),
            ],
        ];
    }

    /**
     * 訂閱來源，並只把勾選的影片加進影片庫。
     *
     * 驗證（頻道上限、影片屬於來源、影片額度）全部在寫入前完成，任一不過就不建立訂閱。
     * 目前 RSS 裡的影片一律先寫進 media，同步時間點才設在之後：沒勾的影片是「訂閱前
     * 就看過」的，之後的同步不會把它們當新影片補進來。
     *
     * @param ResolvedSource $resolved
     * @param list<string> $videoIds 11 碼 YouTube video ID
     * @throws InvalidRequestException
     */
    public function subscribe(User $user, array $resolved, bool $notify, array $videoIds): Source
    {
        $source = $this->sourceService->firstOrCreate(
            $resolved['type'],
            $resolved['external_id'],
            $resolved['title'],
            $resolved['thumbnail']
        );
        $this->fillChannelStatistics($source);

        $alreadySubscribed = $user->sources()->where('sources.id', $source->id)->exists();

        if (!$alreadySubscribed) {
            $this->assertChannelLimit($user);
        }

        $resourceIds = array_values(array_unique(array_map(
            fn (string $videoId) => YoutubeRssFeed::RESOURCE_ID_PREFIX . $videoId,
            $videoIds
        )));
        $this->assertVideosBelongToSource($source, $resolved['entries'], $resourceIds);

        $owned = $user->media()->whereIn('media.resource_id', $resourceIds)->pluck('media.resource_id')->all();
        $toAdd = array_values(array_diff($resourceIds, $owned));
        $remaining = $this->subscriptionService->remainingVideoQuota($user);

        if ($remaining !== null && count($toAdd) > $remaining) {
            throw new InvalidRequestException(
                ['video_ids' => [__('validators.controllers.media.video_limit_reached')]]
            );
        }

        DB::transaction(function () use ($user, $source, $resolved, $notify, $alreadySubscribed, $toAdd): void {
            foreach ($resolved['entries'] as $entry) {
                $this->rssFeed->upsertMedia($source, $entry);
            }

            if ($alreadySubscribed) {
                $user->sources()->updateExistingPivot($source->id, ['notify' => $notify]);
            } else {
                $user->sources()->attach($source->id, ['notify' => $notify, 'synced_at' => now()]);
            }

            if ($toAdd !== []) {
                $user->media()->syncWithoutDetaching(
                    Media::query()->whereIn('resource_id', $toAdd)->pluck('id')->all()
                );
            }
        });

        return $source;
    }

    /**
     * 同步時把新影片加進一位訂閱者的影片庫，再把同步時間點推進到 `$syncedAt`。
     *
     * 新影片 = `media.created_at` 落在 (synced_at, $syncedAt]，且還不在影片庫。依發布時間
     * 新到舊、受剩餘額度限制；額度不夠而沒加入的影片之後不補，時間點照樣往前推。
     * synced_at 為 null（不該出現：migration 已補齊、訂閱時一定會寫）時不補任何影片。
     */
    public function deliverNewMedia(User $user, Source $source, CarbonInterface $syncedAt): void
    {
        $subscription = UserSource::query()
            ->where('user_id', $user->getKey())
            ->where('source_id', $source->id)
            ->first();

        if ($subscription === null) {
            return;
        }

        $cutoff = $subscription->getAttribute('synced_at');
        $remaining = $this->subscriptionService->remainingVideoQuota($user);

        if ($cutoff !== null && $remaining !== 0) {
            $query = $source->media()
                ->where('media.created_at', '>', $cutoff)
                ->where('media.created_at', '<=', $syncedAt)
                ->whereDoesntHave('users', function ($q) use ($user): void {
                    $q->where('users.id', $user->getKey());
                })
                ->orderByDesc('published_at');

            if ($remaining !== null) {
                $query->limit($remaining);
            }

            $mediaIds = $query->pluck('media.id')->all();

            if ($mediaIds !== []) {
                $user->media()->syncWithoutDetaching($mediaIds);
            }
        }

        $subscription->update(['synced_at' => $syncedAt]);
    }

    private function fillChannelStatistics(Source $source): void
    {
        if ($source->type !== Source::TYPE_YOUTUBE_CHANNEL || !empty($source->metadata['subscriber_count'])) {
            return;
        }

        $stats = app(YoutubeService::class)->getChannelStatistics($source->external_id);

        if ($stats) {
            $source->metadata = array_merge($source->metadata ?? [], $stats);
            $source->save();
        }
    }

    /**
     * @throws InvalidRequestException
     */
    private function assertChannelLimit(User $user): void
    {
        $plan = $this->subscriptionService->getUserSubscriptionPlan(
            $this->subscriptionService->getUserSubscription($user->id)
        );

        if ($plan !== null && $plan->channel_limit > 0 && $user->sources()->count() >= $plan->channel_limit) {
            throw new InvalidRequestException(
                ['source' => [__('validators.controllers.sources.channel_limit_reached')]]
            );
        }
    }

    /**
     * 勾選的影片必須在目前的 RSS 清單裡，或已是該來源既有的 media——預覽到送出之間
     * RSS 可能已經更新，後者避免誤擋。
     *
     * @param list<RssEntry> $entries
     * @param list<string> $resourceIds
     * @throws InvalidRequestException
     */
    private function assertVideosBelongToSource(Source $source, array $entries, array $resourceIds): void
    {
        $unknown = array_diff($resourceIds, array_column($entries, 'id'));

        if ($unknown === []) {
            return;
        }

        $known = Media::query()
            ->where('source_id', $source->id)
            ->whereIn('resource_id', $unknown)
            ->count();

        if ($known < count($unknown)) {
            throw new InvalidRequestException(
                ['video_ids' => [__('validators.controllers.sources.video_not_in_source')]]
            );
        }
    }

    /**
     * @param RssEntry $entry
     */
    private function publishedAt(array $entry): ?Carbon
    {
        return $entry['published'] !== '' ? Carbon::parse($entry['published']) : null;
    }

    /**
     * @throws InvalidRequestException
     */
    private function invalidUrl(): never
    {
        throw new InvalidRequestException(['url' => [__('validators.controllers.sources.invalid_url')]]);
    }
}
