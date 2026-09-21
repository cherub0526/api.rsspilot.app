<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Tests\TestCase;
use App\Models\Config;
use App\Utils\AI\OpenRouterRouting;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\FollowUpQuestions\NeuronFollowUpQuestions;

/**
 * @internal
 * @coversNothing
 */
class OpenRouterRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'App/Services/FollowUpQuestions/NeuronFollowUpQuestions';

    /**
     * 沒有設定時完全不帶路由參數——行為要與這個 class 出現之前一致。
     */
    public function testReturnsAnEmptyArrayWhenNothingIsConfigured(): void
    {
        $this->assertSame([], OpenRouterRouting::for(NeuronFollowUpQuestions::class));
    }

    /**
     * 值原封不動傳出去：OpenRouter 的路由選項會變，這裡刻意不定義 schema。
     */
    public function testReturnsTheConfiguredParametersVerbatim(): void
    {
        $params = [
            'plugins'  => [['id' => OpenRouterRouting::PLUGIN_AUTO_ROUTER, 'cost_tier' => OpenRouterRouting::TIER_LOW]],
            'provider' => ['max_price' => ['prompt' => 1, 'completion' => 4]],
        ];

        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [self::KEY => $params]);

        $this->assertSame($params, OpenRouterRouting::for(NeuronFollowUpQuestions::class));
    }

    /**
     * 每個用途各自一組，不會互相污染。
     */
    public function testOtherPurposesAreNotAffected(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [
            'App/Utils/AI/NeuronChatStreamer' => ['provider' => ['sort' => 'price']],
        ]);

        $this->assertSame([], OpenRouterRouting::for(NeuronFollowUpQuestions::class));
    }

    /**
     * 人工改壞資料表不該讓推論整個炸掉——拿不到合法形狀就當作沒設定。
     */
    public function testFallsBackToEmptyWhenTheStoredValueIsNotAnArray(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [self::KEY => 'openrouter/auto']);

        $this->assertSame([], OpenRouterRouting::for(NeuronFollowUpQuestions::class));

        Config::setValue(Config::KEY_OPENROUTER_ROUTING, 'not-an-array');

        $this->assertSame([], OpenRouterRouting::for(NeuronFollowUpQuestions::class));
    }
}
