<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 指令的 scope 與 job 的即時檢查必須是同一套條件（SR-6.2）：不一致就會派出又跳過。
 *
 * @internal
 * @coversNothing
 */
class MediaTranscriptionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: Media, 1: bool}> */
    private function cases(): array
    {
        $owned = Media::factory()->create();
        $owned->users()->attach(User::factory()->create()->id);

        $deletedOwner = Media::factory()->create();
        $gone = User::factory()->create();
        $deletedOwner->users()->attach($gone->id);
        $gone->delete();

        $free = Media::factory()->create(['source_id' => Source::factory()->create(['free' => true])->id]);
        $paid = Media::factory()->create(['source_id' => Source::factory()->create(['free' => false])->id]);

        $deletedFree = Media::factory()->create(['source_id' => Source::factory()->create(['free' => true])->id]);
        Source::query()->whereKey($deletedFree->source_id)->first()?->delete();

        return [
            'collected'                 => [$owned, true],
            'only a deleted collector'  => [$deletedOwner, false],
            'free source, no collector' => [$free, true],
            'paid source, no collector' => [$paid, false],
            'no source, no collector'   => [Media::factory()->create(['source_id' => null]), false],
            'deleted free source'       => [$deletedFree, false],
        ];
    }

    public function testTheScopeAndTheLiveCheckAgree(): void
    {
        $cases = $this->cases();
        $scoped = Media::query()->eligibleForTranscription()->pluck('id')->all();

        foreach ($cases as $name => [$media, $expected]) {
            $this->assertSame($expected, $media->isEligibleForTranscription(), "{$name}: live check");
            $this->assertSame($expected, in_array($media->id, $scoped, true), "{$name}: scope");
        }
    }

    /** SR-6.1：已載入的關聯是派工當時的快照，判斷要即時查 DB。 */
    public function testTheLiveCheckIgnoresLoadedRelations(): void
    {
        $source = Source::factory()->create(['free' => true]);
        $media = Media::factory()->create(['source_id' => $source->id]);
        $user = User::factory()->create();
        $media->users()->attach($user->id);
        $media->load(['users', 'source']);

        $media->users()->detach($user->id);
        $source->update(['free' => false]);

        $this->assertFalse($media->isEligibleForTranscription());
    }
}
