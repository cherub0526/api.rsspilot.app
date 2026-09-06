<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prompts;

use Tests\TestCase;
use App\Models\Config;
use App\Utils\AI\Completion;
use Hypervel\Support\Facades\Http;
use App\Utils\AI\OpenRouterRouting;
use App\Services\Prompts\TemplateFactory;
use Hypervel\Foundation\Testing\RefreshDatabase;
use App\Services\Prompts\TemplateCompletionManager;

/**
 * 路由參數（Auto Router 的 cost tier 等）要真的進到送出的 request body，
 * 否則 configs 設了等於沒設 —— 這種失效是靜默的，只有帳單看得出來。
 *
 * 與模型一樣以模板 class 為 key，所以 summary 與 customPrompt 各自獨立。
 *
 * @internal
 * @coversNothing
 */
class TemplateCompletionManagerRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const LOW_TIER = [
        'plugins' => [['id' => 'auto-router', 'cost_tier' => 'low']],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{}']]],
            ], 200),
        ]);
    }

    private function complete(string $type, array $additionalParams = []): void
    {
        $template = TemplateFactory::create($type, [
            'language'         => 'English',
            'system_prompt'    => 'p',
            'user_prompt'      => 'u',
            'respond_language' => 'English',
        ]);

        (new TemplateCompletionManager(Completion::make(), $template))
            ->complete('逐字稿', '', $additionalParams);
    }

    private function assertSentBody(callable $assert): void
    {
        Http::assertSent(function ($request) use ($assert) {
            $assert($request->data());

            return true;
        });
    }

    // ================================================================

    public function testRoutingParametersReachTheRequestBody(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [
            'App/Services/Prompts/SummaryTemplate' => self::LOW_TIER,
        ]);

        $this->complete('summary');

        $this->assertSentBody(function (array $body): void {
            $this->assertSame(
                [['id' => OpenRouterRouting::PLUGIN_AUTO_ROUTER, 'cost_tier' => OpenRouterRouting::TIER_LOW]],
                $body['plugins']
            );
        });
    }

    /**
     * key 掛在模板 class 上，換一個用途不該影響另一個。
     */
    public function testAnotherTemplateIsNotAffected(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [
            'App/Services/Prompts/SummaryTemplate' => self::LOW_TIER,
        ]);

        $this->complete('customPrompt');

        $this->assertSentBody(function (array $body): void {
            $this->assertArrayNotHasKey('plugins', $body);
        });
    }

    /**
     * 沒設定時 body 不該多出空的路由欄位——送一個空 plugins 陣列給上游沒有意義，
     * 也讓 request log 難讀。
     */
    public function testNothingIsAddedWhenRoutingIsNotConfigured(): void
    {
        $this->complete('summary');

        $this->assertSentBody(function (array $body): void {
            $this->assertArrayNotHasKey('plugins', $body);
            $this->assertArrayNotHasKey('provider', $body);
        });
    }

    /**
     * 路由參數是最底層的預設，呼叫端明講的還是要贏——這是 array_merge 的順序決定的，
     * 順序寫反了不會有任何錯誤訊息。
     */
    public function testExplicitParametersStillOverrideRouting(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [
            'App/Services/Prompts/SummaryTemplate' => self::LOW_TIER,
        ]);

        $this->complete('summary', ['plugins' => []]);

        $this->assertSentBody(function (array $body): void {
            $this->assertSame([], $body['plugins']);
        });
    }

    /**
     * 既有選項不能被路由參數擠掉。
     */
    public function testDefaultOptionsSurvive(): void
    {
        Config::setValue(Config::KEY_OPENROUTER_ROUTING, [
            'App/Services/Prompts/SummaryTemplate' => self::LOW_TIER,
        ]);

        $this->complete('summary');

        $this->assertSentBody(function (array $body): void {
            $this->assertArrayHasKey('messages', $body);
            $this->assertArrayHasKey('model', $body);
            $this->assertArrayHasKey('max_tokens', $body);
        });
    }
}
