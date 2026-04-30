<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prompts;

use App\Services\Prompts\AnalysisTemplate;
use App\Services\Prompts\AssistantTemplate;
use App\Services\Prompts\CaptionTemplate;
use App\Services\Prompts\SummaryTemplate;
use App\Services\Prompts\TemplateFactory;
use App\Services\Prompts\TemplateInterface;
use App\Services\Prompts\TranslationTemplate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;

/**
 * 模板系統單元測試.
 */
class TemplateSystemTest extends \PHPUnit\Framework\TestCase
{
    /**
     * 測試 TemplateFactory 建立模板
     */
    #[DoesNotPerformAssertions]
    public function testFactoryCreatesTemplates(): void
    {
        $types = ['assistant', 'summary', 'translation', 'caption', 'analysis'];

        foreach ($types as $type) {
            $template = TemplateFactory::create($type, []);
            assert($template instanceof TemplateInterface, "Failed to create {$type} template");
            assert($template->getType() === $type, "Template type mismatch for {$type}");
        }

        echo "✓ TemplateFactory creates all template types\n";
    }

    /**
     * 測試 TemplateFactory 拋出異常.
     */
    #[DoesNotPerformAssertions]
    public function testFactoryThrowsExceptionForUnknownType(): void
    {
        try {
            TemplateFactory::create('unknown_type', []);
            assert(false, 'Should throw InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            assert(str_contains($e->getMessage(), 'Unknown template type'), 'Wrong error message');
        }

        echo "✓ TemplateFactory throws exception for unknown type\n";
    }

    /**
     * 測試模板參數管理.
     */
    #[DoesNotPerformAssertions]
    public function testTemplateParameterManagement(): void
    {
        $params = ['key1' => 'value1', 'key2' => 'value2'];
        $template = TemplateFactory::create('summary', $params);

        $retrieved = $template->getParameters();
        assert($retrieved === $params, "Parameters don't match");

        // 測試設定新參數
        $newParams = ['key3' => 'value3'];
        $template->setParameters($newParams);
        $updated = $template->getParameters();
        assert(isset($updated['key1']), 'Original parameter lost');
        assert(isset($updated['key3']), 'New parameter not added');

        echo "✓ Template parameter management works correctly\n";
    }

    /**
     * 測試消息構建.
     */
    #[DoesNotPerformAssertions]
    public function testMessageBuilding(): void
    {
        $template = TemplateFactory::create('caption', [
            'language' => 'Traditional Chinese',
        ]);

        $messages = $template->buildMessages('Test content');

        assert(is_array($messages), 'Messages should be an array');
        assert(count($messages) > 0, 'Messages array should not be empty');
        assert(isset($messages[0]['role']), 'Message should have role');
        assert(isset($messages[0]['content']), 'Message should have content');

        echo "✓ Message building works correctly\n";
    }

    /**
     * 測試不同模板的系統提示詞.
     */
    #[DoesNotPerformAssertions]
    public function testSystemPrompts(): void
    {
        $templates = [
            'summary' => TemplateFactory::create('summary', ['length' => 'short']),
            'translation' => TemplateFactory::create('translation', ['target_language' => 'English']),
            'caption' => TemplateFactory::create('caption', ['language' => 'Traditional Chinese']),
            'analysis' => TemplateFactory::create('analysis', ['depth' => 'deep']),
        ];

        foreach ($templates as $type => $template) {
            $prompt = $template->getSystemPrompt();
            assert(! empty($prompt), "System prompt should not be empty for {$type}");
            assert(is_string($prompt), "System prompt should be a string for {$type}");
        }

        echo "✓ All templates have system prompts\n";
    }

    /**
     * 測試模板類型.
     */
    #[DoesNotPerformAssertions]
    public function testTemplateTypes(): void
    {
        $templates = [
            ['type' => 'assistant', 'class' => AssistantTemplate::class],
            ['type' => 'summary', 'class' => SummaryTemplate::class],
            ['type' => 'translation', 'class' => TranslationTemplate::class],
            ['type' => 'caption', 'class' => CaptionTemplate::class],
            ['type' => 'analysis', 'class' => AnalysisTemplate::class],
        ];

        foreach ($templates as $templateInfo) {
            $template = TemplateFactory::create($templateInfo['type'], []);
            assert(
                $template instanceof $templateInfo['class'],
                "Template instance check failed for {$templateInfo['type']}"
            );
            assert(
                $template->getType() === $templateInfo['type'],
                "Type mismatch for {$templateInfo['type']}"
            );
        }

        echo "✓ All template types are correct\n";
    }

    /**
     * 測試 Factory 註冊和檢查.
     */
    #[DoesNotPerformAssertions]
    public function testFactoryRegistration(): void
    {
        $availableTypes = TemplateFactory::getAvailableTypes();
        assert(is_array($availableTypes), 'Available types should be an array');
        assert(count($availableTypes) > 0, 'Should have available types');

        foreach ($availableTypes as $type) {
            assert(TemplateFactory::has($type), "Factory should have {$type}");
        }

        echo "✓ Factory registration works correctly\n";
    }

    /**
     * 測試多形性 - 多個模板遵循相同介面.
     */
    #[DoesNotPerformAssertions]
    public function testPolymorphism(): void
    {
        $templates = [
            TemplateFactory::create('assistant', []),
            TemplateFactory::create('summary', ['length' => 'short']),
            TemplateFactory::create('translation', ['target_language' => 'English']),
            TemplateFactory::create('caption', ['language' => 'Traditional Chinese']),
            TemplateFactory::create('analysis', ['depth' => 'moderate']),
        ];

        // 所有模板都應該實現相同的介面
        foreach ($templates as $template) {
            assert($template instanceof TemplateInterface, "Template doesn't implement TemplateInterface");

            // 測試所有必需方法
            $systemPrompt = $template->getSystemPrompt();
            $userPrompt = $template->getUserPrompt();
            $type = $template->getType();
            $parameters = $template->getParameters();
            $messages = $template->buildMessages('Test');

            assert(is_string($systemPrompt), 'getSystemPrompt should return string');
            assert(is_string($userPrompt), 'getUserPrompt should return string');
            assert(is_string($type), 'getType should return string');
            assert(is_array($parameters), 'getParameters should return array');
            assert(is_array($messages), 'buildMessages should return array');
        }

        echo "✓ Polymorphism test passed - all templates follow the same interface\n";
    }

    /**
     * 測試模板鏈式調用.
     */
    #[DoesNotPerformAssertions]
    public function testChainableSetters(): void
    {
        $template = TemplateFactory::create('summary', []);

        // 測試返回 self
        $result = $template->setParameters(['key' => 'value']);
        assert($result instanceof TemplateInterface, 'setParameters should return self');

        echo "✓ Chainable setters work correctly\n";
    }

    /**
     * 運行所有測試.
     */
    public static function runAll(): void
    {
        echo "\n╔════════════════════════════════════════════════════════╗\n";
        echo "║        模板系統單元測試 - 多形設計驗證            ║\n";
        echo "╚════════════════════════════════════════════════════════╝\n\n";

        $test = new self();

        $methods = get_class_methods($test);
        $testMethods = array_filter($methods, function ($method) {
            return strpos($method, 'test') === 0;
        });

        foreach ($testMethods as $method) {
            try {
                $test->{$method}();
            } catch (Throwable $e) {
                echo "✗ {$method} failed: " . $e->getMessage() . "\n";
            }
        }

        echo "\n╔════════════════════════════════════════════════════════╗\n";
        echo "║                    所有測試完成                      ║\n";
        echo "╚════════════════════════════════════════════════════════╝\n\n";
    }
}

// 如果直接執行此檔案
if (php_sapi_name() === 'cli' && basename($GLOBALS['_'] ?? $_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    TemplateSystemTest::runAll();
}
