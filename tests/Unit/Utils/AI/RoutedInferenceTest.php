<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Generator;
use Tests\TestCase;
use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Config;
use RuntimeException;
use App\Utils\AI\RoutingProfile;
use App\Utils\AI\RoutedInference;
use Hypervel\Support\Facades\Log;
use App\Utils\AI\NeuronChatStreamer;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * 方案路由失敗時退回用途層。串流只在第一個 token 之前可以退。
 *
 * @internal
 * @coversNothing
 */
class RoutedInferenceTest extends TestCase
{
    use RefreshDatabase;

    private const PURPOSE = NeuronChatStreamer::class;

    private const PURPOSE_KEY = 'App/Utils/AI/NeuronChatStreamer';

    protected function setUp(): void
    {
        parent::setUp();

        Config::setValue(Config::KEY_OPENROUTER_MODELS, [self::PURPOSE_KEY => 'openrouter/auto']);
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [self::PURPOSE_KEY => []]);
    }

    /**
     * @param null|array<string, mixed> $routing
     */
    private function userOnPlan(?array $routing): User
    {
        $plan = Plan::withoutEvents(fn () => Plan::factory()->create([
            'title'      => 'Free',
            'ai_routing' => $routing,
            'status'     => Plan::STATUS_ACTIVE,
        ]));

        Price::withoutEvents(fn () => Price::factory()->create([
            'plan_id' => $plan->id,
            'unit'    => Price::UNIT_MONTHLY,
            'price'   => 0,
        ]));

        return User::factory()->create();
    }

    // ================================================================

    public function testRunReturnsTheResultOfThePrimaryProfile(): void
    {
        $user = $this->userOnPlan(['model' => 'openrouter/free']);

        $model = RoutedInference::run(self::PURPOSE, $user, fn (RoutingProfile $p): string => $p->model);

        $this->assertSame('openrouter/free', $model);
    }

    public function testRunRetriesWithThePurposeProfileWhenThePrimaryFails(): void
    {
        $user = $this->userOnPlan(['model' => 'openrouter/free']);
        Log::shouldReceive('warning')->once();

        $seen = [];
        $model = RoutedInference::run(self::PURPOSE, $user, function (RoutingProfile $p) use (&$seen): string {
            $seen[] = $p->model;

            if ($p->model === 'openrouter/free') {
                throw new RuntimeException('rate limited');
            }

            return $p->model;
        });

        $this->assertSame(['openrouter/free', 'openrouter/auto'], $seen);
        $this->assertSame('openrouter/auto', $model);
    }

    /**
     * 方案本來就沒覆寫用途時不重試——再送一次一模一樣的請求只是把同一個錯誤吃兩遍。
     */
    public function testRunDoesNotRetryWhenTheProfilesAreIdentical(): void
    {
        $user = $this->userOnPlan(null);
        Log::shouldReceive('warning')->never();

        $calls = 0;

        $this->expectException(RuntimeException::class);

        try {
            RoutedInference::run(self::PURPOSE, $user, function () use (&$calls): string {
                ++$calls;

                throw new RuntimeException('down');
            });
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testStreamRetriesWhenThePrimaryFailsBeforeTheFirstToken(): void
    {
        $user = $this->userOnPlan(['model' => 'openrouter/free']);
        Log::shouldReceive('warning')->once();

        $stream = RoutedInference::stream(self::PURPOSE, $user, function (RoutingProfile $p): Generator {
            if ($p->model === 'openrouter/free') {
                throw new RuntimeException('rate limited');
            }

            yield 'a';
            yield 'b';
        });

        $this->assertSame(['a', 'b'], iterator_to_array($stream, false));
    }

    /**
     * 已經吐給前端的內容收不回來，中途換模型會讓使用者看到兩段接不起來的文字。
     */
    public function testStreamDoesNotRetryAfterTheFirstTokenHasBeenYielded(): void
    {
        $user = $this->userOnPlan(['model' => 'openrouter/free']);
        Log::shouldReceive('warning')->never();

        $stream = RoutedInference::stream(self::PURPOSE, $user, function (RoutingProfile $p): Generator {
            yield 'first';

            throw new RuntimeException('died mid-stream');
        });

        $this->expectException(RuntimeException::class);

        iterator_to_array($stream, false);
    }
}
