<?php

declare(strict_types=1);

namespace App\Console\Commands\Sources;

use App\Models\User;
use App\Models\Source;
use Hypervel\Console\Command;
use App\Services\YoutubeRssFeed;
use App\Services\SourceSubscriptionService;

class Sync extends Command
{
    protected ?string $signature = 'sources:sync
        {--id= : Sync a specific source by ID, bypassing the free/subscribed filter}
        {--free : Only sync sources with free=1}';

    protected string $description = 'Sync videos from source RSS feeds into the media table';

    public function handle(): void
    {
        $query = Source::query()->where('status', Source::STATUS_ACTIVE);

        if ($id = $this->option('id')) {
            // 明確指定 ID 是人工意圖（補跑、驗證單一來源），略過下面的過濾。
            $query->where('id', $id);
        } else {
            // 只同步「免費來源」或「至少有一位使用者訂閱的來源」。
            // POST /v1/media 手動加入單支影片時會順手把該影片的頻道建成 source，
            // 那種沒人訂閱又非免費的來源如果照樣同步，等於替沒人要的頻道抓整份
            // RSS、建整批 media，成本卻沒有任何使用者在對應。
            $query->where(function ($builder) {
                $builder->where('free', true)
                    ->orWhereHas('userSources');
            });
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

        $rssFeed = app(YoutubeRssFeed::class);
        $entries = $rssFeed->fetchEntries($source->url);

        if ($entries === null) {
            $this->warn("    Failed to fetch RSS: {$source->url}");
            return;
        }

        $created = 0;
        $linked = 0;

        foreach ($entries as $entry) {
            $media = $rssFeed->upsertMedia($source, $entry);

            if ($media->wasRecentlyCreated) {
                ++$created;
            } elseif ($media->wasChanged('source_id')) {
                ++$linked;
            }
        }

        $source->update(['last_synced_at' => now()]);

        // 每位訂閱者只收同步時間點之後第一次出現的影片，再把時間點推進到現在。
        // 時間在 upsert 之後才取，本輪建立的 media 一定落在 (synced_at, $syncedAt] 內。
        $syncedAt = now();
        $subscriptionService = app(SourceSubscriptionService::class);
        User::query()
            ->whereHas('sources', fn ($q) => $q->where('sources.id', $source->id))
            ->chunkById(100, function ($users) use ($source, $subscriptionService, $syncedAt) {
                foreach ($users as $user) {
                    $subscriptionService->deliverNewMedia($user, $source, $syncedAt);
                }
            });

        $this->line("    created={$created} linked={$linked}");
    }
}
