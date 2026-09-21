<?php

declare(strict_types=1);

namespace Tests\Unit\Utils\AI;

use Tests\TestCase;
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

    /**
     * 方案自己指定的思考力度要蓋過預設值。
     *
     * 這是 Advance 想得比較久的實作方式——旋鈕在 `plans.ai_routing`，跟 cost_tier
     * 與 max_price 放同一處，改資料不必部署。
     */
    public function testThePlanRoutingOverridesTheDefaultEffort(): void
    {
        config(['ai.chat.reasoning_effort' => 'low']);

        $parameters = (new NeuronChatStreamer())->parametersFor(
            new RoutingProfile('openrouter/auto', [
                'reasoning' => ['effort' => 'medium', 'exclude' => false],
            ]),
            null,
            true
        );

        $this->assertSame(['effort' => 'medium', 'exclude' => false], $parameters['reasoning']);
    }

    /** 沒開推理的路徑，方案指定了也不該硬塞進去。 */
    public function testThePlanRoutingIsStillRespectedWhenReasoningIsOff(): void
    {
        $parameters = (new NeuronChatStreamer())->parametersFor(
            new RoutingProfile('openrouter/auto', [
                'reasoning' => ['effort' => 'medium', 'exclude' => false],
            ]),
            null,
            false
        );

        // 方案自己寫的參數照送（這一層不做產品判斷），但不會再疊上 config 的預設值。
        $this->assertSame(['effort' => 'medium', 'exclude' => false], $parameters['reasoning']);
    }

    /**
     * 上網查資料送的是頂層的 tools 與 max_tool_calls，不是 NeuronAI 的工具物件。
     *
     * 形狀錯了不會報錯，只是 OpenRouter 不認得、模型就是不搜——所以每個鍵都要
     * 斷言到，包括 engine/mode 是巢狀在 parameters 底下而不是攤平在 tool 上。
     */
    public function testWebSearchSendsTheServerToolShape(): void
    {
        config([
            'ai.chat.web_search.engine'         => 'parallel',
            'ai.chat.web_search.mode'           => 'turbo',
            'ai.chat.web_search.max_results'    => 3,
            'ai.chat.web_search.max_tool_calls' => 2,
        ]);

        $parameters = (new NeuronChatStreamer())->webSearchParameters();

        $this->assertSame(
            [['type' => 'openrouter:web_search', 'parameters' => [
                'engine'      => 'parallel',
                'mode'        => 'turbo',
                'max_results' => 3,
            ]]],
            $parameters['tools']
        );
        $this->assertSame(2, $parameters['max_tool_calls'], '上游預設 30，沒壓住成本就跟著走');
    }

    /**
     * max_tool_calls 不能是 0：那會被上游讀成「不准用工具」，等於功能靜默關閉。
     */
    public function testWebSearchKeepsAtLeastOneToolCall(): void
    {
        config(['ai.chat.web_search.max_tool_calls' => 0]);

        $this->assertSame(1, (new NeuronChatStreamer())->webSearchParameters()['max_tool_calls']);
    }

    /** engine / mode 留空代表「用上游預設」，送空字串只會換來一個 400。 */
    public function testWebSearchOmitsEmptyEngineAndMode(): void
    {
        config([
            'ai.chat.web_search.engine'      => '',
            'ai.chat.web_search.mode'        => '',
            'ai.chat.web_search.max_results' => 5,
        ]);

        $tool = (new NeuronChatStreamer())->webSearchParameters()['tools'][0];

        $this->assertSame(['max_results' => 5], $tool['parameters']);
    }

    /** 關著的時候一個字都不該送出去——否則沒開通的方案也會被上游收搜尋費。 */
    public function testParametersCarryNoToolsWhenWebSearchIsOff(): void
    {
        $parameters = (new NeuronChatStreamer())->parametersFor(
            new RoutingProfile('openrouter/auto'),
            null,
            false,
            false
        );

        $this->assertArrayNotHasKey('tools', $parameters);
        $this->assertArrayNotHasKey('max_tool_calls', $parameters);
    }

    /** 開著的時候要疊在路由參數之上，而不是把它們蓋掉。 */
    public function testWebSearchParametersMergeOntoRouting(): void
    {
        config([
            'ai.chat.web_search.engine'         => 'parallel',
            'ai.chat.web_search.mode'           => 'turbo',
            'ai.chat.web_search.max_results'    => 3,
            'ai.chat.web_search.max_tool_calls' => 2,
        ]);

        $parameters = (new NeuronChatStreamer())->parametersFor(
            new RoutingProfile('openrouter/auto', ['provider' => ['max_price' => ['prompt' => 1.5]]]),
            null,
            false,
            true
        );

        $this->assertSame(['max_price' => ['prompt' => 1.5]], $parameters['provider']);
        $this->assertSame('openrouter:web_search', $parameters['tools'][0]['type']);
        $this->assertSame(2, $parameters['max_tool_calls']);
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
