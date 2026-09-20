<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Tests\TestCase;
use ReflectionProperty;
use App\Utils\AI\RoutingProfile;
use App\Utils\AI\NeuronChatStreamer;

/**
 * 送給 OpenRouter 的路由參數怎麼組。
 *
 * 真正送出去的 body 攔不到（NeuronAI 自己建 Guzzle client，Http::fake() 管不到），
 * 所以 parametersFor() 是這件事唯一測得到的層級。
 *
 * @internal
 * @coversNothing
 */
class NeuronChatStreamerTest extends TestCase
{
    /**
     * 預設不要推理內容：推理 token 按 output 計價，只有真的會顯示給使用者看的
     * 路徑才值得付這筆錢。
     */
    public function testDoesNotAskForReasoningUnlessRequested(): void
    {
        $parameters = (new NeuronChatStreamer())
            ->parametersFor(new RoutingProfile('openrouter/auto'), null, false);

        $this->assertArrayNotHasKey('reasoning', $parameters);
    }

    /**
     * exclude 一定要是 false——只讓模型多想一輪卻拿不到內容，等於白花錢。
     */
    public function testAsksForReasoningWithTheConfiguredEffort(): void
    {
        config(['ai.chat.reasoning_effort' => 'high']);

        $parameters = (new NeuronChatStreamer())
            ->parametersFor(new RoutingProfile('openrouter/auto'), null, true);

        $this->assertSame(['effort' => 'high', 'exclude' => false], $parameters['reasoning']);
    }

    /** 設定關掉或寫錯時完全不送——送一個上游不收的值只會換到一個 400。 */
    public function testDisabledOrInvalidEffortSendsNothing(): void
    {
        foreach (['', 'off', 'extreme'] as $value) {
            config(['ai.chat.reasoning_effort' => $value]);

            $this->assertArrayNotHasKey(
                'reasoning',
                (new NeuronChatStreamer())->parametersFor(new RoutingProfile('m'), null, true),
                "effort={$value} 應該等同關閉"
            );
        }
    }

    /** 沒設 Tavily key 就當作沒有這個工具——不報錯，功能就是不存在。 */
    public function testNoWebSearchToolWithoutAKey(): void
    {
        config(['ai.chat.web_search.tavily_key' => '']);

        $this->assertNull((new NeuronChatStreamer())->webSearchTool());
    }

    /**
     * 有 key 時掛上 Tavily，而且 include_answer 一定要在——TavilySearchTool 的
     * __invoke() 直接讀 $result['answer']，沒要求 Tavily 產生摘要的話每次搜尋都
     * 會炸在那一行。
     */
    public function testWebSearchToolCarriesTheOptionsTavilyNeeds(): void
    {
        config([
            'ai.chat.web_search.tavily_key'  => 'tvly-test',
            'ai.chat.web_search.max_results' => 5,
            'ai.chat.web_search.max_runs'    => 2,
        ]);

        $tool = (new NeuronChatStreamer())->webSearchTool();

        $this->assertNotNull($tool);
        $this->assertSame('web_search', $tool->getName());
        $this->assertSame(2, $tool->getMaxRuns(), '每一輪最多搜幾次要擋住，成本與延遲都在這裡');

        $options = (new ReflectionProperty($tool, 'options'))->getValue($tool);
        $this->assertTrue($options['include_answer']);
        $this->assertSame(5, $options['max_results']);
    }

    /** sticky routing 與推理是兩件事，同時開時兩個都要在，也不能蓋掉路由參數。 */
    public function testKeepsTheStickyRoutingKeyAndTheProfileParameters(): void
    {
        config(['ai.chat.reasoning_effort' => 'low']);

        $parameters = (new NeuronChatStreamer())->parametersFor(
            new RoutingProfile('m', ['cost_tier' => 'low']),
            'sess-1',
            true
        );

        $this->assertSame('sess-1', $parameters['session_id']);
        $this->assertSame('low', $parameters['cost_tier']);
        $this->assertArrayHasKey('reasoning', $parameters);
    }
}
