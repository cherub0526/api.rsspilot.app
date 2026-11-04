<?php

declare(strict_types=1);

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Media;
use App\Models\Source;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * security-hotfix-1 驗收（H2）：`GET /v1/media?keyword=` 只在自己的影片庫、原本的 type 條件內搜尋，
 * keyword 的 `%`、`_` 當字面字元（S07、S09）。測試跑 SQLite，沒寫 ESCAPE 的跳脫不會生效。
 *
 * 斷言看影片 id 在不在回應裡，不只看筆數。
 *
 * @internal
 * @coversNothing
 */
class MediaSearchScopeTest extends TestCase
{
    use RefreshDatabase;

    private function collected(User $user, string $title, string $sourceTitle, string $type = Media::TYPE_YOUTUBE): Media
    {
        $source = Source::factory()->create(['title' => $sourceTitle]);
        $media = Media::factory()->create(['type' => $type, 'title' => $title, 'source_id' => $source->id]);
        $user->media()->attach($media->id);

        return $media;
    }

    /** @return list<string> */
    private function search(string $keyword, string $type = Media::TYPE_YOUTUBE): array
    {
        $response = $this->json('GET', route('api.v1.media.index'), ['type' => $type, 'keyword' => $keyword])
            ->assertStatus(200);

        return collect($response->json('data'))->pluck('id')->all();
    }

    // ── S06 ───────────────────────────────────────────

    public function testS06SearchOnlyReturnsMediaInTheUsersOwnLibrary(): void
    {
        $a = $this->fakeLogin();
        $alpha = $this->collected($a, 'Alpha', 'Source X');
        $gamma = $this->collected(User::factory()->create(), 'Gamma', 'Beta Channel');

        $ids = $this->search('Beta');

        $this->assertNotContains($gamma->id, $ids, "B's video does not leak");
        $this->assertNotContains($alpha->id, $ids);
        $this->assertSame([], $ids);
    }

    // ── S07 ───────────────────────────────────────────

    public function testS07WildcardsInTheKeywordAreLiteral(): void
    {
        $a = $this->fakeLogin();
        $alpha = $this->collected($a, 'Alpha', 'Source X');
        $gamma = $this->collected(User::factory()->create(), 'Gamma', 'Beta Channel');

        foreach (['%', '_'] as $keyword) {
            $ids = $this->search($keyword);

            $this->assertNotContains($gamma->id, $ids, "keyword {$keyword}: B's video does not leak");
            $this->assertNotContains($alpha->id, $ids, "keyword {$keyword}: matches nothing literally");
            $this->assertSame([], $ids, "keyword {$keyword}");
        }
    }

    // ── S09 ───────────────────────────────────────────

    /** SQLite 沒有預設的跳脫字元：只把 % 換成 \% 而沒寫 ESCAPE，兩支都找不到或都找到。 */
    public function testS09APercentSignInTheKeywordMatchesOnlyALiteralPercentSign(): void
    {
        $a = $this->fakeLogin();
        $literal = $this->collected($a, '100% Rust', 'Source X');
        $this->collected($a, '1000 Rust', 'Source Y');

        $this->assertSame([$literal->id], $this->search('100%'));
    }

    /** qa 邊界：_ 也是字面字元，不是單一字元萬用。 */
    public function testAnUnderscoreInTheKeywordMatchesOnlyALiteralUnderscore(): void
    {
        $a = $this->fakeLogin();
        $literal = $this->collected($a, 'snake_case tips', 'Source X');
        $this->collected($a, 'snakeXcase tips', 'Source Y');

        $this->assertSame([$literal->id], $this->search('e_c'));
    }

    /** qa 邊界（T02 r1）：來源標題那一支 OR 也要跳脫。 */
    public function testAPercentSignMatchesASourceTitleOnlyLiterally(): void
    {
        $a = $this->fakeLogin();
        $literal = $this->collected($a, 'Episode 1', '100% Club');
        $this->collected($a, 'Episode 2', '1000 Club');

        $this->assertSame([$literal->id], $this->search('100%'));
    }

    /** qa 邊界（T02 r1）：keyword 的群組沒有吃掉 range 條件。 */
    public function testTheRangeFilterStillAppliesToAKeywordMatch(): void
    {
        $a = $this->fakeLogin();
        $recent = $this->collected($a, 'Ownership 101', 'Rust Weekly');
        $recent->fill(['published_at' => now()])->save();
        $old = $this->collected($a, 'Ownership 100', 'Rust Weekly');
        $old->fill(['published_at' => now()->subYears(2)])->save();

        $response = $this->json('GET', route('api.v1.media.index'), ['type' => Media::TYPE_YOUTUBE, 'keyword' => 'Rust', 'range' => 'week'])
            ->assertStatus(200);

        $this->assertSame([$recent->id], collect($response->json('data'))->pluck('id')->all());
    }

    // ── S08 ───────────────────────────────────────────

    public function testS08SearchFindsOwnMediaByVideoTitleOrSourceTitle(): void
    {
        $a = $this->fakeLogin();
        $video = $this->collected($a, 'Ownership 101', 'Rust Weekly');

        $this->assertSame([$video->id], $this->search('Ownership'));
        $this->assertSame([$video->id], $this->search('Rust'));
    }

    public function testS08TheTypeFilterStillAppliesToASourceTitleMatch(): void
    {
        $a = $this->fakeLogin();
        $video = $this->collected($a, 'Ownership 101', 'Rust Weekly');
        $podcast = $this->collected($a, 'Borrowing 202', 'Rust Weekly', Media::TYPE_SPOTIFY);

        $this->assertSame([$video->id], $this->search('Rust'));
        $this->assertSame([$podcast->id], $this->search('Rust', Media::TYPE_SPOTIFY));
    }
}
