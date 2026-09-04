<?php

declare(strict_types=1);

namespace App\Services\Prompts;

use InvalidArgumentException;

/**
 * 模板工廠
 * 負責建立和管理不同類型的模板物件.
 */
class TemplateFactory
{
    /**
     * @var array 註冊的模板類別映射
     */
    private static array $templates = [
        'assistant'           => AssistantTemplate::class,
        'summary'             => SummaryTemplate::class,
        'translation'         => TranslationTemplate::class,
        'caption'             => CaptionTemplate::class,
        'analysis'            => AnalysisTemplate::class,
        'customPrompt'        => CustomPromptTemplate::class,
        'follow_up_questions' => FollowUpQuestionsTemplate::class,
        'mindmap'             => MindMapTemplate::class,
    ];

    /**
     * 建立模板實例.
     *
     * @param string $type 模板類型
     * @param array $parameters 模板參數
     * @throws InvalidArgumentException
     */
    public static function create(string $type, array $parameters = []): TemplateInterface
    {
        if (!isset(self::$templates[$type])) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown template type: %s. Available types: %s',
                    $type,
                    implode(', ', array_keys(self::$templates))
                )
            );
        }

        $templateClass = self::$templates[$type];
        return new $templateClass($parameters);
    }

    /**
     * 註冊自訂模板
     *
     * @param string $type 模板類型
     * @param string $className 模板類別名稱
     */
    public static function register(string $type, string $className): void
    {
        if (!is_subclass_of($className, TemplateInterface::class)) {
            throw new InvalidArgumentException(
                sprintf('%s must implement %s', $className, TemplateInterface::class)
            );
        }

        self::$templates[$type] = $className;
    }

    /**
     * 取得所有可用的模板類型.
     */
    public static function getAvailableTypes(): array
    {
        return array_keys(self::$templates);
    }

    /**
     * 檢查模板類型是否存在.
     */
    public static function has(string $type): bool
    {
        return isset(self::$templates[$type]);
    }
}
