<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Tests\TestCase;
use App\Models\Source;
use Hypervel\Foundation\Testing\RefreshDatabase;

class SourceTest extends TestCase
{
    use RefreshDatabase;

    public function testCanCreateSource(): void
    {
        $source = Source::create([
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
            'title'       => 'Test Channel',
            'url'         => 'https://www.youtube.com/channel/UCxxxxxxxxxxxxxxxxxxxxxx',
        ]);

        $this->assertDatabaseHas('sources', [
            'external_id' => 'UCxxxxxxxxxxxxxxxxxxxxxx',
            'type'        => Source::TYPE_YOUTUBE_CHANNEL,
        ]);

        $this->assertNotNull($source->id);
    }

    public function testScopeActive(): void
    {
        Source::factory()->create(['status' => Source::STATUS_ACTIVE]);
        Source::factory()->create(['status' => 'inactive']);

        $this->assertCount(1, Source::active()->get());
    }
}
