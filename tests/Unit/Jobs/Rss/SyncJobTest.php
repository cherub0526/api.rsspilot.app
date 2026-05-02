<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Rss;

use App\Models\Rss;
use App\Models\Plan;
use App\Models\User;
use App\Models\Media;
use App\Models\Price;
use App\Jobs\Rss\SyncJob;
use Mockery\MockInterface;
use App\Models\Subscription;
use App\Services\YoutubeService;
use App\Services\SubscriptionService;
use Hypervel\Foundation\Testing\TestCase;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Queue;

/**
 * @internal
 * @covers \App\Jobs\Rss\SyncJob
 */
class SyncJobTest extends TestCase
{
    use RefreshDatabase;

    private string $fakeRssPath;

    private Plan $plan;

    private Price $price;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a fake RSS XML file for testing
        $this->fakeRssPath = storage_path('framework/testing/fake_rss.xml');
        $dir = dirname($this->fakeRssPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->fakeRssPath, $this->getFakeRssXml());

        Queue::fake();

        $this->plan = Plan::withoutEvents(function () {
            return Plan::factory()->create(['video_limit' => 5]);
        });

        $this->price = Price::withoutEvents(function () {
            return Price::factory()->create([
                'plan_id' => $this->plan->id,
                'price'   => 0,
                'unit'    => Price::UNIT_MONTHLY,
            ]);
        });
    }

    protected function tearDown(): void
    {
        // Clean up the fake file
        if (file_exists($this->fakeRssPath)) {
            unlink($this->fakeRssPath);
        }
        parent::tearDown();
    }

    public function testSyncJobSuccessfullyNoSubscriptionToUsers(): void
    {
        $user = User::factory()->create();
        $rss = Rss::factory()->create(['url' => $this->fakeRssPath]);
        $rss->users()->attach($user->id);

        $job = new SyncJob($rss);
        $job->handle();

        $this->assertDatabaseCount('media', 2);

        $media1 = Media::where('resource_id', 'yt:video:video1')->first();
        $media2 = Media::where('resource_id', 'yt:video:video2')->first();

        $this->assertNotNull($media1);
        $this->assertEquals('Test Video 1', $media1->title);
        $this->assertNotNull($media2);
        $this->assertEquals('Test Video 2', $media2->title);

        // Assert that the user is associated with the 2 new media items
        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $media1->id,
            'rss_id'   => $rss->id,
        ]);
        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $media2->id,
            'rss_id'   => $rss->id,
        ]);
        $this->assertEquals(2, $user->media()->count());
    }

    public function testSyncJobSuccessfullyCreatesMediaAndAttachesToUsers(): void
    {
        // 1. Arrange
        $user = User::factory()->create();
        $rss = Rss::factory()->create(['url' => $this->fakeRssPath]);
        $rss->users()->attach($user->id);

        $subscription = Subscription::factory()->create([
            'user_id'  => $user->id,
            'plan_id'  => $this->plan->id,
            'price_id' => $this->price->id,
        ]);

        // Mock SubscriptionService
        $this->mock(SubscriptionService::class, function (MockInterface $mock) use ($subscription) {
            $mock->shouldReceive('getUserSubscription')->andReturn($subscription);
            $mock->shouldReceive('getUserSubscriptionPlan')->andReturn($this->plan);
        });

        // 2. Act
        $job = new SyncJob($rss);
        $job->handle();

        // 3. Assert
        // Assert that 2 media items were created from the XML
        $this->assertDatabaseCount('media', 2);

        $media1 = Media::where('resource_id', 'yt:video:video1')->first();
        $media2 = Media::where('resource_id', 'yt:video:video2')->first();

        $this->assertNotNull($media1);
        $this->assertEquals('Test Video 1', $media1->title);
        $this->assertNotNull($media2);
        $this->assertEquals('Test Video 2', $media2->title);

        // Assert that the user is associated with the 2 new media items
        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $media1->id,
            'rss_id'   => $rss->id,
        ]);
        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $media2->id,
            'rss_id'   => $rss->id,
        ]);
        $this->assertEquals(2, $user->media()->count());

        // Run the job again to test idempotency
        $job->handle();
        $this->assertDatabaseCount('media', 2); // Should not create duplicates
        $this->assertEquals(2, $user->media()->count()); // Should not attach duplicates
    }

    public function testSyncJobRespectsUserVideoLimit(): void
    {
        // 1. Arrange
        $user = User::factory()->create();
        $rss = Rss::factory()->create(['url' => $this->fakeRssPath]);
        $rss->users()->attach($user->id);

        // User has a limit of 5, but has already used 4
        $subscription = Subscription::factory()->create([
            'user_id'  => $user->id,
            'plan_id'  => $this->plan->id,
            'price_id' => $this->price->id,
        ]);
        Media::factory()->count(4)->create()->each(function ($media) use ($user) {
            $user->media()->attach($media->id);
        });

        $this->mock(SubscriptionService::class, function (MockInterface $mock) use ($subscription) {
            $mock->shouldReceive('getUserSubscription')->andReturn($subscription);
            $mock->shouldReceive('getUserSubscriptionPlan')->andReturn($this->plan);
        });

        // 2. Act
        $job = new SyncJob($rss);
        $job->handle();

        // 3. Assert
        // The XML has 2 videos, but user only has quota for 1
        $this->assertDatabaseCount('media', 4 + 2); // 4 existing + 2 new
        $this->assertEquals(4 + 1, $user->media()->count());
    }

    public function testSyncJobFromPlaylistApi(): void
    {
        $user = User::factory()->create();
        $rss = Rss::factory()->create([
            'url'     => 'https://www.youtube.com/feeds/videos.xml?playlist_id=PLtest123',
            'list_id' => 'PLtest123',
        ]);
        $rss->users()->attach($user->id);

        $subscription = Subscription::factory()->create([
            'user_id'  => $user->id,
            'plan_id'  => $this->plan->id,
            'price_id' => $this->price->id,
        ]);

        $this->mock(SubscriptionService::class, function (MockInterface $mock) use ($subscription) {
            $mock->shouldReceive('getUserSubscription')->andReturn($subscription);
            $mock->shouldReceive('getUserSubscriptionPlan')->andReturn($this->plan);
        });

        $this->mock(YoutubeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getPlaylistItems')->with('PLtest123')->andReturn([
                [
                    'id'           => 'yt:video:plvideo1',
                    'yt:videoId'   => 'plvideo1',
                    'yt:channelId' => 'UCtest',
                    'title'        => 'Playlist Video 1',
                    'description'  => 'Description 1',
                    'link'         => 'https://www.youtube.com/watch?v=plvideo1',
                    'author'       => ['name' => 'Test Channel', 'uri' => 'https://www.youtube.com/channel/UCtest'],
                    'published'    => '2024-01-01T00:00:00+00:00',
                    'updated'      => '2024-01-01T00:00:00+00:00',
                    'media'        => [
                        'description' => 'Description 1',
                        'thumbnail'   => ['url' => 'https://i.ytimg.com/vi/plvideo1/hqdefault.jpg'],
                        'content'     => ['url' => ''],
                        'community'   => [
                            'statistics' => ['views' => '0'],
                            'starRating' => ['average' => '0', 'max' => '5', 'min' => '1', 'count' => '0'],
                        ],
                    ],
                ],
                [
                    'id'           => 'yt:video:plvideo2',
                    'yt:videoId'   => 'plvideo2',
                    'yt:channelId' => 'UCtest',
                    'title'        => 'Playlist Video 2',
                    'description'  => 'Description 2',
                    'link'         => 'https://www.youtube.com/watch?v=plvideo2',
                    'author'       => ['name' => 'Test Channel', 'uri' => 'https://www.youtube.com/channel/UCtest'],
                    'published'    => '2024-01-02T00:00:00+00:00',
                    'updated'      => '2024-01-02T00:00:00+00:00',
                    'media'        => [
                        'description' => 'Description 2',
                        'thumbnail'   => ['url' => 'https://i.ytimg.com/vi/plvideo2/hqdefault.jpg'],
                        'content'     => ['url' => ''],
                        'community'   => [
                            'statistics' => ['views' => '0'],
                            'starRating' => ['average' => '0', 'max' => '5', 'min' => '1', 'count' => '0'],
                        ],
                    ],
                ],
            ]);
        });

        $job = new SyncJob($rss);
        $job->handle();

        $this->assertDatabaseCount('media', 2);

        $media1 = Media::where('resource_id', 'yt:video:plvideo1')->first();
        $media2 = Media::where('resource_id', 'yt:video:plvideo2')->first();

        $this->assertNotNull($media1);
        $this->assertEquals('Playlist Video 1', $media1->title);
        $this->assertNotNull($media2);
        $this->assertEquals('Playlist Video 2', $media2->title);

        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $media1->id,
            'rss_id'   => $rss->id,
        ]);
        $this->assertDatabaseHas('userables', [
            'user_id'  => $user->id,
            'media_id' => $media2->id,
            'rss_id'   => $rss->id,
        ]);
    }

    private function getFakeRssXml(): string
    {
        return <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
    <link rel="self" href="http://www.youtube.com/feeds/videos.xml?channel_id=TEST_CHANNEL"/>
    <id>yt:channel:TEST_CHANNEL</id>
    <yt:channelId>TEST_CHANNEL</yt:channelId>
    <title>Test Channel</title>
    <author>
        <name>Test Channel</name>
        <uri>http://www.youtube.com/channel/TEST_CHANNEL</uri>
    </author>
    <entry>
        <id>yt:video:video1</id>
        <yt:videoId>video1</yt:videoId>
        <yt:channelId>TEST_CHANNEL</yt:channelId>
        <title>Test Video 1</title>
        <link rel="alternate" href="http://www.youtube.com/watch?v=video1"/>
        <author>
            <name>Test Channel</name>
            <uri>http://www.youtube.com/channel/TEST_CHANNEL</uri>
        </author>
        <published>2023-01-01T00:00:00+00:00</published>
        <updated>2023-01-01T00:00:00+00:00</updated>
        <media:group>
            <media:title>Test Video 1</media:title>
            <media:content url="https://www.youtube.com/v/video1?version=3" type="application/x-shockwave-flash" width="640" height="390"/>
            <media:thumbnail url="https://i2.ytimg.com/vi/video1/hqdefault.jpg" width="480" height="360"/>
            <media:description>Description for video 1</media:description>
            <media:community>
                <media:starRating count="100" average="4.5" min="1" max="5"/>
                <media:statistics views="1000"/>
            </media:community>
        </media:group>
    </entry>
    <entry>
        <id>yt:video:video2</id>
        <yt:videoId>video2</yt:videoId>
        <yt:channelId>TEST_CHANNEL</yt:channelId>
        <title>Test Video 2</title>
        <link rel="alternate" href="http://www.youtube.com/watch?v=video2"/>
        <author>
            <name>Test Channel</name>
            <uri>http://www.youtube.com/channel/TEST_CHANNEL</uri>
        </author>
        <published>2023-01-02T00:00:00+00:00</published>
        <updated>2023-01-02T00:00:00+00:00</updated>
        <media:group>
            <media:title>Test Video 2</media:title>
            <media:content url="https://www.youtube.com/v/video2?version=3" type="application/x-shockwave-flash" width="640" height="390"/>
            <media:thumbnail url="https://i2.ytimg.com/vi/video2/hqdefault.jpg" width="480" height="360"/>
            <media:description>Description for video 2</media:description>
            <media:community>
                <media:starRating count="200" average="4.8" min="1" max="5"/>
                <media:statistics views="2000"/>
            </media:community>
        </media:group>
    </entry>
</feed>
XML;
    }
}
