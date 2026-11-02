<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Tests\TestCase;
use App\Utils\AI\Completion;
use Hypervel\Support\Facades\Http;

/**
 * @internal
 * @coversNothing
 */
class CompletionTest extends TestCase
{
    private function timeoutOf(callable $call): mixed
    {
        $timeout = null;
        Http::fake(function ($request, array $options) use (&$timeout) {
            $timeout = $options['timeout'] ?? null;

            return Http::response(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $call(new Completion('key', 'https://openrouter.test/api/v1'));

        return $timeout;
    }

    public function testCompletionsKeepTheSixtySecondDefault(): void
    {
        $this->assertEquals(60, $this->timeoutOf(fn (Completion $c) => $c->completions('m', [])));
    }

    /**
     * 頻道 Skill 一次送數萬 token、要一整份 SKILL.md 回來，60 秒等不到。
     */
    public function testCompletionsAcceptALongerTimeout(): void
    {
        $this->assertEquals(240, $this->timeoutOf(fn (Completion $c) => $c->completions('m', [], [], 240)));
    }
}
