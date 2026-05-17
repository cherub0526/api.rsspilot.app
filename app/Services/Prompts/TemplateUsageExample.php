<?php

declare(strict_types=1);

namespace App\Services\Prompts;

use App\Utils\AI\Completion;

/**
 * 模板系統使用指南.
 *
 * ============================================
 * 模板系統架構 (多形設計)
 * ============================================
 *
 * 1. TemplateInterface - 定義所有模板必須實現的介面
 * 2. BaseTemplate - 提供基礎實現，內含共通邏輯
 * 3. 具體實現類別:
 *    - AssistantTemplate: 一般助手任務
 *    - SummaryTemplate: 摘要和總結
 *    - TranslationTemplate: 文本翻譯
 *    - CaptionTemplate: 字幕生成
 *    - AnalysisTemplate: 內容分析
 *
 * 4. TemplateFactory - 工廠模式，管理模板建立
 * 5. TemplateCompletionManager - 整合 Completion 服務
 *
 * ============================================
 * 使用範例
 * ============================================
 */
class TemplateUsageExample
{
    /**
     * 範例 1: 使用工廠建立模板
     *
     * $template = TemplateFactory::create('summary', [
     *     'length' => 'short',
     *     'language' => 'Traditional Chinese',
     *     'user_prompt' => 'Summarize this article'
     * ]);
     */
    public static function example1(): void
    {
        // 使用工廠建立摘要模板
        $template = TemplateFactory::create('summary', [
            'length'   => 'medium',
            'language' => 'Traditional Chinese',
        ]);

        echo 'Template Type: ' . $template->getType() . "\n";
        echo "System Prompt:\n" . $template->getSystemPrompt() . "\n";
    }

    /**
     * 範例 2: 使用管理器與 OpenAI 整合.
     *
     * $completion = new Completion($apiKey);
     * $template = TemplateFactory::create('caption', [...parameters...]);
     * $manager = new TemplateCompletionManager($completion, $template);
     *
     * $result = $manager->complete($videoTranscript);
     */
    public static function example2(): void
    {
        // 初始化 Completion 服務
        $completion = Completion::make();

        // 建立字幕生成模板
        $template = TemplateFactory::create('caption', [
            'language' => 'Traditional Chinese',
            'style'    => 'professional',
        ]);

        // 建立管理器
        $manager = new TemplateCompletionManager($completion, $template);

        // 設定 OpenAI 選項
        $manager->setOptions([
            'temperature' => 0.5,
            'max_tokens'  => 4000,
        ]);

        // 執行完成請求
        // $response = $manager->complete('Video transcript here...');
        // $content = $manager->completeAndGetContent('Video transcript here...');
    }

    /**
     * 範例 3: 切換模板
     */
    public static function example3(): void
    {
        $completion = Completion::make();

        // 建立初始模板
        $template = TemplateFactory::create('summary', [
            'length' => 'short',
        ]);

        $manager = new TemplateCompletionManager($completion, $template);

        // 切換到翻譯模板
        $template = TemplateFactory::create('translation', [
            'source_language' => 'English',
            'target_language' => 'Traditional Chinese',
        ]);

        $manager->setTemplate($template);

        // 執行翻譯
        // $result = $manager->complete('English text to translate...');
    }

    /**
     * 範例 4: 註冊自訂模板
     */
    public static function example4(): void
    {
        // 註冊自訂模板
        TemplateFactory::register('custom', CustomTemplate::class);

        // 使用自訂模板
        $template = TemplateFactory::create('custom', [
            'param1' => 'value1',
        ]);
    }

    /**
     * 範例 5: 檢查可用模板類型.
     */
    public static function example5(): void
    {
        $availableTypes = TemplateFactory::getAvailableTypes();
        echo 'Available template types: ' . implode(', ', $availableTypes) . "\n";

        if (TemplateFactory::has('summary')) {
            echo "Summary template is available\n";
        }
    }

    /**
     * 範例 6: 直接建立和使用模板 (多形性).
     */
    public static function example6(): void
    {
        // 可以存儲不同類型的模板在同一個陣列中 (多形性)
        $templates = [
            TemplateFactory::create('assistant', []),
            TemplateFactory::create('summary', ['length' => 'short']),
            TemplateFactory::create('translation', ['target_language' => 'English']),
            TemplateFactory::create('caption', ['language' => 'Traditional Chinese']),
            TemplateFactory::create('analysis', ['depth' => 'deep']),
        ];

        // 遍歷並使用每個模板
        foreach ($templates as $template) {
            echo 'Processing template type: ' . $template->getType() . "\n";
            $messages = $template->buildMessages('Some content here');
            echo 'Messages count: ' . count($messages) . "\n\n";
        }
    }

    /**
     * 範例 7: 建立自訂模板
     *
     * 建立一個自訂模板類別:
     *
     * class CustomTemplate extends BaseTemplate
     * {
     *     protected string $type = 'custom';
     *
     *     public function getSystemPrompt(): string
     *     {
     *         return 'Your custom system prompt';
     *     }
     *
     *     public function getUserPrompt(): string
     *     {
     *         return $this->parameters['user_prompt'] ?? '';
     *     }
     * }
     *
     * 然後註冊並使用:
     * TemplateFactory::register('custom', CustomTemplate::class);
     * $template = TemplateFactory::create('custom', [...]);
     */
    public static function example7(): void
    {
        echo "See comments above for custom template creation\n";
    }
}
