<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prompts;

use Tests\TestCase;
use App\Utils\AI\Completion;
use Hypervel\Support\Facades\Http;
use App\Services\Prompts\TemplateFactory;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\Prompts\TemplateCompletionManager;

/**
 * @internal
 * @coversNothing
 */
class TemplateCompletionManagerTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private function timeoutOf(?int $timeout): mixed
    {
        $seen = null;
        Http::fake(function ($request, array $options) use (&$seen) {
            $seen = $options['timeout'] ?? null;

            return Http::response(['choices' => [['message' => ['content' => 'ok']]]]);
        });

        $manager = new TemplateCompletionManager(Completion::make(), TemplateFactory::create('mindmap'));
        if ($timeout !== null) {
            $manager->setTimeout($timeout);
        }
        $manager->completeAndGetContent('text');

        return $seen;
    }

    public function testDefaultsToTheCompletionTimeout(): void
    {
        $this->assertEquals(60, $this->timeoutOf(null));
    }

    public function testPassesAnExplicitTimeoutThrough(): void
    {
        $this->assertEquals(240, $this->timeoutOf(240));
    }
}
