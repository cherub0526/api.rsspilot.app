<?php

declare(strict_types=1);

namespace App\Jobs\Rss;

use Carbon\Carbon;
use App\Models\Rss;
use App\Models\Media;
use Hypervel\Queue\Queueable;
use App\Services\SubscriptionService;
use Hypervel\Queue\Contracts\ShouldQueue;
use App\Jobs\Media\YoutubeCaptionJob;

class SyncJob implements ShouldQueue
{
    use Queueable;

    protected Rss $rss;

    /**
     * Create a new job instance.
     */
    public function __construct(Rss $rss)
    {
        $this->rss = $rss;

        $this->queue = 'rss.sync';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $xml = simplexml_load_file($this->rss->url);

        if ($xml === false) {
            return;
        }

        $namespaces = $xml->getNamespaces(true);

        echo '頻道名稱: ' . $xml->title . PHP_EOL;

        $insertData = [];

        foreach ($xml->entry as $entry) {
            $data = [
                'id'           => (string) $entry->id,
                'yt:videoId'   => (string) $entry->children($namespaces['yt'])->videoId,
                'yt:channelId' => (string) $entry->children($namespaces['yt'])->channelId,
                'title'        => (string) $entry->title,
                'description'  => (string) $entry->description,
                'link'         => (string) $entry->link['href'],
                'author'       => [
                    'name' => (string) $entry->author->name,
                    'uri'  => (string) $entry->author->uri,
                ],
                'published' => (string) $entry->published,
                'updated'   => (string) $entry->updated,
                'media'     => json_decode(json_encode($entry->children($namespaces['media'])->group), true),
            ];
            $data['media']['content']['url'] = (string) $entry->children(
                $namespaces['media']
            )->group->content->attributes()['url'];
            $data['media']['thumbnail']['url'] = (string) $entry->children(
                $namespaces['media']
            )->group->thumbnail->attributes()['url'];
            $data['media']['community']['statistics']['views'] = (string) $entry->children(
                $namespaces['media']
            )->group->community->statistics->attributes()['views'];
            $data['media']['community']['starRating'] = [
                'average' => (string) $entry->children($namespaces['media'])->group->community->starRating->attributes(
                )['average'],
                'max' => (string) $entry->children($namespaces['media'])->group->community->starRating->attributes(
                )['max'],
                'min' => (string) $entry->children($namespaces['media'])->group->community->starRating->attributes(
                )['min'],
                'count' => (string) $entry->children($namespaces['media'])->group->community->starRating->attributes(
                )['count'],
            ];
            $insertData[] = $data;
        }

        $ids = array_column($insertData, 'id');

        $medias = Media::whereIn('resource_id', $ids)->select(['id', 'resource_id'])->get();
        $intersectIds = array_values(array_intersect($ids, $medias->pluck('resource_id')->toArray()));

        foreach ($insertData as $data) {
            if (in_array($data['id'], $intersectIds)) {
                continue;
            }

            $media = Media::create([
                'type'        => Media::TYPE_YOUTUBE,
                'title'       => $data['title'],
                'resource_id' => $data['id'],
                'description' => is_string($data['media']['description'])
                    ? $data['media']['description']
                    : '',
                'duration'     => 0,
                'thumbnail'    => $data['media']['thumbnail']['url'] ?? '',
                'published_at' => Carbon::parse($data['published']),
                'status'       => Media::STATUS_CREATED,
                'video_detail' => $data,
                'audio_detail' => [],
            ]);
            $medias->push($media);
        }

        $dispatchedMediaIds = [];

        $this->rss->users()->chunkById(100, function ($users) use ($medias, $ids, &$dispatchedMediaIds) {
            $betweenDays = [
                now()->subDays(30)->startOfDay(),
                now()->endOfDay(),
            ];
            foreach ($users as $user) {
                $count = $user->media()->whereBetween('userables.created_at', $betweenDays)->count();

                $subscriptionService = app(SubscriptionService::class);
                $subscription = $subscriptionService->getUserSubscription($user->id);
                $plan = $subscriptionService->getUserSubscriptionPlan($subscription);
                $remainCount = $plan->video_limit - $count;

                if ($remainCount <= 0) {
                    continue;
                }

                $existsMedias = $user->media()->whereIn('resource_id', $ids)->get(['resource_id']);

                $userMedias = $medias->whereNotIn('resource_id', $existsMedias->pluck('resource_id')->toArray())->take(
                    $remainCount
                );

                $syncData = [];

                $userMedias->each(function ($media) use (&$syncData) {
                    $syncData[$media->id] = ['rss_id' => $this->rss->id];
                });
                $user->media()->syncWithoutDetaching($syncData);

                foreach ($userMedias as $media) {
                    if (in_array($media->id, $dispatchedMediaIds)) {
                        continue;
                    }

                    YoutubeCaptionJob::dispatch($media);
                    $dispatchedMediaIds[] = $media->id;
                }
            }
        });
    }
}
