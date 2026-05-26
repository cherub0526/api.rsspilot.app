<?php

declare(strict_types=1);

namespace App\Services\Prompts;

use App\Utils\AI\Completion;
use Psr\Http\Message\ResponseInterface;

/**
 * 模板完成管理器
 * 負責將模板與 AI Completion 服務整合.
 */
class TemplateCompletionManager
{
    /**
     * @var Completion AI 完成服務
     */
    private Completion $completion;

    /**
     * @var TemplateInterface 當前使用的模板
     */
    private TemplateInterface $template;

    /**
     * @var array OpenAI 選項
     */
    private array $options = [];

    /**
     * 建構函式.
     */
    public function __construct(Completion $completion, TemplateInterface $template)
    {
        $this->completion = $completion;
        $this->template = $template;
    }

    /**
     * 設定模板
     */
    public function setTemplate(TemplateInterface $template): self
    {
        $this->template = $template;
        return $this;
    }

    /**
     * 取得當前模板
     */
    public function getTemplate(): TemplateInterface
    {
        return $this->template;
    }

    /**
     * 設定 OpenAI 選項.
     *
     * @param array $options (e.g., max_tokens, temperature, top_p)
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * 執行完成請求
     *
     * @param string $userContent 使用者內容
     * @param string $model OpenAI 模型 (default: gpt-3.5-turbo)
     * @param array $additionalParams 額外參數
     * @return array OpenAI 回應
     */
    public function complete(
        string $userContent,
        string $model = '',
        array $additionalParams = []
    ): array {
        if ($model === '') {
            $model = config('ai.default_model');
        }
        // 建立消息陣列
        $messages = $this->template->buildMessages($userContent, $additionalParams);

        // 合併選項
        $options = array_merge(
            $this->getDefaultOptions(),
            $this->options,
            $additionalParams
        );

        // 呼叫 OpenAI API
        return $this->completion->completions($model, $messages, $options);
    }

    /**
     * 發送串流完成請求，回傳可逐行讀取的 PSR-7 ResponseInterface。
     * Body 為 OpenRouter SSE 格式（text/event-stream）。
     *
     * @param string $userContent 使用者輸入
     * @param string $model       模型名稱（空字串使用 config 預設值）
     */
    public function completeStream(string $userContent, string $model = ''): ResponseInterface
    {
        if ($model === '') {
            $model = config('ai.default_model');
        }

        $messages = $this->template->buildMessages($userContent);

        return $this->completion->streamCompletions($model, $messages, $this->options);
    }

    /**
     * 執行完成請求並返回內容.
     *
     * @param string $userContent 使用者內容
     * @param string $model OpenAI 模型
     * @param array $additionalParams 額外參數
     * @return string 完成內容
     */
    public function completeAndGetContent(
        string $userContent,
        string $model = '',
        array $additionalParams = []
    ): string {
        $response = $this->complete($userContent, $model, $additionalParams);

        return $response['choices'][0]['message']['content'] ?? '';
    }

    /**
     * 取得預設選項.
     */
    private function getDefaultOptions(): array
    {
        return [
            'temperature' => 0.7,
            'max_tokens'  => 2000,
        ];
    }
}
