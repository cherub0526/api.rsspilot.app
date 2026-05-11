<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\WatchHistory;
use Hypervel\Foundation\Testing\RefreshDatabase;

class WatchHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function testCanRecordWatchHistory(): void
    {
        $user  = User::factory()->create();
        $media = Media::factory()->create();

        $history = WatchHistory::create([
            'user_id'          => $user->id,
            'media_id'         => $media->id,
            'progress_seconds' => 120,
            'completed'        => false,
            'watched_at'       => now(),
        ]);

        $this->assertDatabaseHas('watch_history', [
            'user_id'          => $user->id,
            'media_id'         => $media->id,
            'progress_seconds' => 120,
        ]);

        $this->assertNotNull($history->id);
    }

    public function testUserWatchHistoryRelationship(): void
    {
        $user  = User::factory()->create();
        $media = Media::factory()->create();

        WatchHistory::create([
            'user_id'    => $user->id,
            'media_id'   => $media->id,
            'watched_at' => now(),
        ]);

        $this->assertCount(1, $user->watchHistory);
        $this->assertEquals($media->id, $user->watchHistory->first()->media_id);
    }
}
