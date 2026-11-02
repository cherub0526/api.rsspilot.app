<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Support\Str;
use App\Models\UserSource;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * source-video-picker S10：上線前的訂閱以 migration 執行時間為同步時間點，
 * 之後不再補舊影片。
 *
 * RefreshDatabase 跑 migration 時 user_sources 是空的，所以先 down，建好既有訂閱，
 * 再 up 一次，模擬它在正式資料上執行。
 *
 * @internal
 * @coversNothing
 */
class AddSyncedAtToUserSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testS10ExistingSubscriptionDoesNotBackfillOldMediaAfterMigration(): void
    {
        $files = glob(database_path('migrations/*_add_synced_at_to_user_sources_table.php')) ?: [];
        $this->assertCount(1, $files, 'expected exactly one *_add_synced_at_to_user_sources_table migration');

        $migration = require $files[0];
        $migration->down();

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));

        $source = Source::factory()->create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => 'UCs10',
            'url'         => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCs10',
            'status'      => Source::STATUS_ACTIVE,
        ]);
        $mediaE = Media::factory()->create([
            'type'        => Media::TYPE_YOUTUBE,
            'resource_id' => 'yt:video:s10videoEEE',
            'source_id'   => $source->id,
            'status'      => Media::STATUS_READY,
        ]);
        $user = User::factory()->create();
        DB::table('user_sources')->insert([
            'id'         => strtolower(Str::ulid()->toString()),
            'user_id'    => $user->id,
            'source_id'  => $source->id,
            'notify'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migratedAt = Carbon::parse('2026-11-02 09:00:00');
        Carbon::setTestNow($migratedAt);
        $migration->up();

        $syncedAt = UserSource::query()->where('user_id', $user->id)->firstOrFail()->synced_at;
        $this->assertInstanceOf(Carbon::class, $syncedAt);
        $this->assertTrue($syncedAt->equalTo($migratedAt));

        Http::fake([
            '*channel_id=UCs10' => Http::response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns="http://www.w3.org/2005/Atom">
                  <entry>
                    <id>yt:video:s10videoEEE</id>
                    <yt:videoId>s10videoEEE</yt:videoId>
                    <title>E</title>
                    <published>2026-09-01T10:00:00+00:00</published>
                  </entry>
                </feed>
                XML, 200),
        ]);

        Carbon::setTestNow($migratedAt->copy()->addHour());
        $this->artisan('sources:sync')->assertExitCode(0);

        $this->assertDatabaseMissing('userables', ['user_id' => $user->id, 'media_id' => $mediaE->id]);
    }
}
