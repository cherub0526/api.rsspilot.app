<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * sources:sync —— 只有免費或有人訂閱的來源才值得花成本抓整份 RSS。
 *
 * @internal
 * @coversNothing
 */
class SourcesSyncTest extends TestCase
{
    use RefreshDatabase;

    private function feed(string $videoId): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"
                  xmlns:media="http://search.yahoo.com/mrss/"
                  xmlns="http://www.w3.org/2005/Atom">
              <entry>
                <id>yt:video:{$videoId}</id>
                <yt:videoId>{$videoId}</yt:videoId>
                <yt:channelId>UCchannel</yt:channelId>
                <title>影片 {$videoId}</title>
                <link rel="alternate" href="https://www.youtube.com/watch?v={$videoId}"/>
                <published>2026-03-01T10:00:00+00:00</published>
                <media:group>
                  <media:description>描述</media:description>
                  <media:thumbnail url="https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg"/>
                </media:group>
              </entry>
            </feed>
            XML;
    }

    /**
     * 每個來源給一個專屬的 RSS 網址與影片 ID，才能從 media 反推哪些來源真的被同步了。
     */
    private function makeSource(string $videoId, bool $free = false): Source
    {
        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => 'UC' . $videoId,
            'url'         => 'https://www.youtube.com/feeds/videos.xml?channel_id=UC' . $videoId,
            'status'      => Source::STATUS_ACTIVE,
            'free'        => $free,
        ]);

        Http::fake([
            '*channel_id=UC' . $videoId => Http::response($this->feed($videoId), 200),
        ]);

        return $source;
    }

    private function assertSynced(string $videoId): void
    {
        $this->assertDatabaseHas('media', ['resource_id' => 'yt:video:' . $videoId]);
    }

    private function assertNotSynced(string $videoId): void
    {
        $this->assertDatabaseMissing('media', ['resource_id' => 'yt:video:' . $videoId]);
    }

    // ================================================================

    public function testSyncsFreeSources(): void
    {
        $this->makeSource('freevid', free: true);

        $this->artisan('sources:sync')->assertExitCode(0);

        $this->assertSynced('freevid');
    }

    public function testSyncsSourcesWithAtLeastOneSubscriber(): void
    {
        $source = $this->makeSource('subvid');

        $user = User::factory()->create();
        $user->sources()->attach($source->id);

        $this->artisan('sources:sync')->assertExitCode(0);

        $this->assertSynced('subvid');
    }

    /**
     * POST /v1/media 手動加入單支影片會順手建出該影片的頻道 source。沒人訂閱又非免費，
     * 同步它等於替沒人要的頻道抓整份 RSS、建整批 media。
     */
    public function testSkipsSourcesThatAreNeitherFreeNorSubscribed(): void
    {
        $this->makeSource('orphanvid');

        $this->artisan('sources:sync')->assertExitCode(0);

        $this->assertNotSynced('orphanvid');
        $this->assertDatabaseCount('media', 0);
    }

    public function testSkippedSourceIsNotMarkedAsSynced(): void
    {
        $source = $this->makeSource('orphanvid');

        $this->artisan('sources:sync');

        $this->assertNull($source->fresh()->last_synced_at);
    }

    /**
     * 明確指定 ID 是人工意圖（補跑、驗證單一來源），略過 free/訂閱過濾。
     */
    public function testIdOptionBypassesTheFilter(): void
    {
        $source = $this->makeSource('orphanvid');

        $this->artisan('sources:sync', ['--id' => $source->id])->assertExitCode(0);

        $this->assertSynced('orphanvid');
    }

    /**
     * 過濾條件疊在既有的 status 判斷之上，不是取代它。
     */
    public function testStillSkipsInactiveFreeSources(): void
    {
        $source = $this->makeSource('inactivevid', free: true);
        $source->update(['status' => Source::STATUS_INACTIVE]);

        $this->artisan('sources:sync')->assertExitCode(0);

        $this->assertNotSynced('inactivevid');
    }

    /**
     * --free 是在新過濾之上再收窄，不是放寬。
     */
    public function testFreeOptionNarrowsFurther(): void
    {
        $paid = $this->makeSource('paidvid');
        $this->makeSource('freevid', free: true);

        $user = User::factory()->create();
        $user->sources()->attach($paid->id);

        $this->artisan('sources:sync', ['--free' => true])->assertExitCode(0);

        $this->assertSynced('freevid');
        $this->assertNotSynced('paidvid');
    }

    /**
     * 有訂閱者的來源同步進來的新影片，會照既有規則寫進訂閱者的影片庫。
     */
    public function testNewMediaLandsInSubscriberLibrary(): void
    {
        $source = $this->makeSource('subvid');

        $user = User::factory()->create();
        $user->sources()->attach($source->id);

        $this->artisan('sources:sync');

        $media = Media::query()->where('resource_id', 'yt:video:subvid')->first();

        $this->assertNotNull($media);
        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $media->id,
        ]);
    }
}
