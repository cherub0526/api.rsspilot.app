<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use App\Models\UserSource;
use Hypervel\Foundation\Testing\RefreshDatabase;

class UserSourceTest extends TestCase
{
    use RefreshDatabase;

    public function testUserCanSubscribeToSource(): void
    {
        $user   = User::factory()->create();
        $source = Source::factory()->create();

        $user->sources()->attach($source->id);

        $this->assertDatabaseHas('user_sources', [
            'user_id'   => $user->id,
            'source_id' => $source->id,
        ]);

        $this->assertCount(1, $user->sources);
    }

    public function testUniqueConstraintPreventsduplicates(): void
    {
        $user   = User::factory()->create();
        $source = Source::factory()->create();

        $user->sources()->attach($source->id);

        $this->expectException(\Throwable::class);
        $user->sources()->attach($source->id);
    }
}
