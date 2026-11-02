<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Models\Source;
use App\Models\UserSource;
use App\Models\Subscription;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function feed(string ...$videoIds): string
    {
        $entries = '';

        foreach ($videoIds as $videoId) {
            $entries .= <<<XML
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

                XML;
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"
                  xmlns:media="http://search.yahoo.com/mrss/"
                  xmlns="http://www.w3.org/2005/Atom">
            {$entries}</feed>
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
     * 有訂閱者的來源同步進來的新影片，會寫進訂閱者的影片庫。
     */
    public function testNewMediaLandsInSubscriberLibrary(): void
    {
        $source = $this->makeSource('subvid');

        $user = User::factory()->create();
        $user->sources()->attach($source->id, ['synced_at' => now()->subHour()]);

        $this->artisan('sources:sync');

        $media = Media::query()->where('resource_id', 'yt:video:subvid')->first();

        $this->assertNotNull($media);
        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $media->id,
        ]);
    }

    // ================================================================
    // source-video-picker：同步只收同步時間點之後第一次出現的影片
    // ================================================================

    public function testS06SyncAddsOnlyMediaCreatedAfterSyncedAt(): void
    {
        $t0 = Carbon::parse('2026-11-01 10:00:00');
        $source = $this->makeFeedSource('UCs06', ['s06videoAAA', 's06videoBBB']);

        Carbon::setTestNow($t0->copy()->subDay());
        $mediaA = $this->makeSourceMedia($source, 's06videoAAA');

        Carbon::setTestNow($t0);
        $user = User::factory()->create();
        $user->sources()->attach($source->id, ['synced_at' => $t0]);

        $syncTime = $t0->copy()->addHour();
        Carbon::setTestNow($syncTime);
        $this->artisan('sources:sync')->assertExitCode(0);

        $mediaB = Media::query()->where('resource_id', 'yt:video:s06videoBBB')->firstOrFail();

        $this->assertDatabaseHas('userables', ['user_id' => $user->id, 'media_id' => $mediaB->id]);
        $this->assertDatabaseMissing('userables', ['user_id' => $user->id, 'media_id' => $mediaA->id]);
        $this->assertTrue($this->syncedAt($user, $source)->equalTo($syncTime));
    }

    /**
     * 時間基準是系統第一次看到影片（media.created_at），不是 published_at——
     * YouTube RSS 會延遲，比照發布時間會漏收晚進 RSS 的影片。
     */
    public function testS07VideoPublishedBeforeSyncedAtButFirstSeenAfterIsAdded(): void
    {
        $t0 = Carbon::parse('2026-11-01 10:00:00');
        $source = $this->makeFeedSource('UCs07', ['s07videoCCC']);

        Carbon::setTestNow($t0);
        $user = User::factory()->create();
        $user->sources()->attach($source->id, ['synced_at' => $t0]);

        Carbon::setTestNow($t0->copy()->addHour());
        $this->artisan('sources:sync')->assertExitCode(0);

        $mediaC = Media::query()->where('resource_id', 'yt:video:s07videoCCC')->firstOrFail();

        $this->assertTrue(Carbon::parse($mediaC->published_at)->lessThan($t0));
        $this->assertDatabaseHas('userables', ['user_id' => $user->id, 'media_id' => $mediaC->id]);
    }

    public function testS08QuotaSkippedVideoIsNeverBackfilled(): void
    {
        $t0 = Carbon::parse('2026-11-01 10:00:00');
        $source = $this->makeFeedSource('UCs08', ['s08videoDDD']);

        Carbon::setTestNow($t0);
        $user = $this->userWithVideoLimit(1);
        $user->media()->attach(Media::factory()->create(['status' => Media::STATUS_READY])->id);
        $user->sources()->attach($source->id, ['synced_at' => $t0]);

        $firstSync = $t0->copy()->addHour();
        Carbon::setTestNow($firstSync);
        $this->artisan('sources:sync')->assertExitCode(0);

        $mediaD = Media::query()->where('resource_id', 'yt:video:s08videoDDD')->firstOrFail();

        $this->assertDatabaseMissing('userables', ['user_id' => $user->id, 'media_id' => $mediaD->id]);
        $this->assertTrue($this->syncedAt($user, $source)->equalTo($firstSync));

        // 額度滾出 30 天窗口後再同步，D 仍然不補
        Carbon::setTestNow($t0->copy()->addDays(40));
        $this->artisan('sources:sync')->assertExitCode(0);

        $this->assertDatabaseMissing('userables', ['user_id' => $user->id, 'media_id' => $mediaD->id]);
    }

    /**
     * 額度只夠一支時，新影片依發布時間新到舊挑。
     */
    public function testSyncPicksNewestPublishedFirstWhenQuotaIsShort(): void
    {
        $t0 = Carbon::parse('2026-11-01 10:00:00');
        $source = $this->makeFeedSource('UCnewest', ['oldervideo1', 'newervideo1']);

        Carbon::setTestNow($t0);
        $user = $this->userWithVideoLimit(1);
        $user->sources()->attach($source->id, ['synced_at' => $t0]);

        Carbon::setTestNow($t0->copy()->addHour());
        $older = $this->makeSourceMedia($source, 'oldervideo1', ['published_at' => $t0->copy()->subDays(2)]);
        $newer = $this->makeSourceMedia($source, 'newervideo1', ['published_at' => $t0->copy()->subDay()]);

        $this->artisan('sources:sync')->assertExitCode(0);

        $this->assertDatabaseHas('userables', ['user_id' => $user->id, 'media_id' => $newer->id]);
        $this->assertDatabaseMissing('userables', ['user_id' => $user->id, 'media_id' => $older->id]);
    }

    /**
     * @param list<string> $videoIds
     */
    private function makeFeedSource(string $channelId, array $videoIds): Source
    {
        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => $channelId,
            'url'         => 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId,
            'status'      => Source::STATUS_ACTIVE,
            'free'        => false,
        ]);

        Http::fake([
            '*channel_id=' . $channelId => Http::response($this->feed(...$videoIds), 200),
        ]);

        return $source;
    }

    private function makeSourceMedia(Source $source, string $videoId, array $attributes = []): Media
    {
        return Media::factory()->create(array_merge([
            'type'        => Media::TYPE_YOUTUBE,
            'resource_id' => 'yt:video:' . $videoId,
            'source_id'   => $source->id,
            'status'      => Media::STATUS_CREATED,
        ], $attributes));
    }

    private function userWithVideoLimit(int $limit): User
    {
        $user = User::factory()->create();
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create(['video_limit' => $limit]));
        $price = Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 9.99,
        ]));
        Subscription::factory()->create([
            'user_id'    => $user->id,
            'plan_id'    => $plan->id,
            'price_id'   => $price->id,
            'status'     => Subscription::STATUS_ACTIVE,
            'start_date' => now()->subDay(),
        ]);

        return $user;
    }

    private function syncedAt(User $user, Source $source): Carbon
    {
        $syncedAt = UserSource::query()
            ->where('user_id', $user->id)
            ->where('source_id', $source->id)
            ->firstOrFail()
            ->synced_at;

        $this->assertInstanceOf(Carbon::class, $syncedAt);

        return $syncedAt;
    }
}
