<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\Like;
use Tests\TestCase;
use App\Models\Media;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 關鍵字裡的 %、_、\ 都只當一般字元。測試跑 SQLite，它的 LIKE 沒有預設跳脫字元，
 * 所以這裡同時守住「有明確寫 ESCAPE」。
 *
 * @internal
 * @coversNothing
 */
class LikeTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function titlesContaining(string $keyword): array
    {
        foreach (['100% Rust', '1000 Rust', 'snake_case', 'snakeXcase', 'C:\temp', 'C:Xtemp'] as $title) {
            Media::factory()->create(['title' => $title]);
        }

        $titles = Like::contains(Media::query(), 'media.title', $keyword)->pluck('title')->all();
        sort($titles);

        return $titles;
    }

    public function testAPercentSignIsLiteral(): void
    {
        $this->assertSame(['100% Rust'], $this->titlesContaining('100%'));
    }

    public function testAnUnderscoreIsLiteral(): void
    {
        $this->assertSame(['snake_case'], $this->titlesContaining('e_c'));
    }

    public function testABackslashIsLiteral(): void
    {
        $this->assertSame(['C:\temp'], $this->titlesContaining(':\t'));
    }

    public function testAPlainKeywordMatchesAnywhereInTheValue(): void
    {
        $this->assertSame(['100% Rust', '1000 Rust'], $this->titlesContaining('Rust'));
    }
}
