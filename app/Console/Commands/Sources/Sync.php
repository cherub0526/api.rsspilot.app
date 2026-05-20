<?php

declare(strict_types=1);

namespace App\Console\Commands\Sources;

use Carbon\Carbon;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Console\Command;
use Hypervel\Support\Facades\Http;

class Sync extends Command
{
    protected ?string $signature = 'sources:sync
        {--id= : Sync a specific source by ID}
        {--free : Only sync sources with free=1}';

    protected string $description = 'Sync videos from source RSS feeds into the media table';

    public function handle(): void
    {
        $query = Source::query()->where('status', Source::STATUS_ACTIVE);

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        if ($this->option('free')) {
            $query->where('free', true);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No sources found.');
            return;
        }

        $this->info("Syncing {$total} source(s)...");

        $query->chunkById(50, function ($sources) {
            foreach ($sources as $source) {
                $this->syncSource($source);
            }
        });

        $this->info('Done.');
    }

    private function syncSource(Source $source): void
    {
        $this->line("  → [{$source->type}] {$source->title}");

        $entries = $this->fetchRssEntries($source->url);

        if ($entries === null) {
            $this->warn("    Failed to fetch RSS: {$source->url}");
            return;
        }

        $created = 0;
        $linked  = 0;

        foreach ($entries as $entry) {
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

            if ($media->wasRecentlyCreated) {
                ++$created;
                continue;
            }

            if (! $media->source_id) {
                $media->update(['source_id' => $source->id]);
                ++$linked;
            }
        }

        $source->update(['last_synced_at' => now()]);

        $this->line("    created={$created} linked={$linked}");
    }

    /**
     * Fetch and parse RSS XML entries from the given URL.
     *
     * @return null|array<int, array{
     *   id: string,
     *   yt:videoId: string,
     *   yt:channelId: string,
     *   title: string,
     *   description: string,
     *   thumbnail: string,
     *   published: string,
     *   video_detail: array<string, mixed>
     * }>
     */
    private function fetchRssEntries(string $url): ?array
    {
        try {
            $response = Http::get($url);
        } catch (\Exception) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            return null;
        }

        $namespaces = $xml->getNamespaces(true);
        $entries    = [];

        foreach ($xml->entry as $entry) {
            $yt    = $entry->children($namespaces['yt'] ?? '');
            $media = $entry->children($namespaces['media'] ?? '');

            $videoId   = (string) ($yt->videoId ?? '');
            $channelId = (string) ($yt->channelId ?? '');

            $thumbnail  = '';
            $mediaGroup = $media->group ?? null;
            if ($mediaGroup) {
                $thumbAttr = $mediaGroup->thumbnail?->attributes();
                $thumbnail = (string) ($thumbAttr['url'] ?? '');
            }

            $description = '';
            if ($mediaGroup) {
                $description = (string) ($mediaGroup->description ?? '');
            }

            $data = [
                'id'           => (string) $entry->id,
                'yt:videoId'   => $videoId,
                'yt:channelId' => $channelId,
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

            $entries[] = $data;
        }

        return $entries;
    }
}
