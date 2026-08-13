<?php

declare(strict_types=1);

namespace App\Services\Prompts;

/**
 * 基礎模板類別
 * 提供通用的模板功能.
 */
abstract class BaseTemplate implements TemplateInterface
{
    /**
     * @var array 模板參數
     */
    protected array $parameters = [];

    /**
     * @var string 模板類型
     */
    protected string $type = 'base';

    /**
     * 建構函式.
     */
    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
    }

    /**
     * 取得模板類型.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 取得模板的參數.
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * 設定參數.
     */
    public function setParameters(array $parameters): self
    {
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }

    /**
     * 構建用於 OpenAI API 的消息陣列.
     */
    public function buildMessages(string $userContent, array $additionalParams = []): array
    {
        $messages = [];

        // 新增系統提示詞
        $systemPrompt = $this->getSystemPrompt();
        if (!empty($systemPrompt)) {
            $messages[] = [
                'role'    => 'system',
                'content' => $systemPrompt,
            ];
        }

        // 對話歷史。優先取呼叫端當下傳入的，其次取模板自身的參數。
        //
        // 走參數這條路的原因：TemplateCompletionManager::complete() 會把
        // $additionalParams 同時當成 buildMessages 的參數「與」OpenRouter 的 options，
        // 而 Completion::completions() 是 array_merge(['messages' => $messages], $options)，
        // 所以 additionalParams 裡的 messages 反而會覆蓋掉這裡組好的訊息陣列。
        // completeStream() 更是完全不轉交 $additionalParams。
        $history = $additionalParams['messages'] ?? $this->parameters['messages'] ?? null;

        if (is_array($history)) {
            foreach ($history as $message) {
                if (isset($message['role'], $message['content'])) {
                    $messages[] = [
                        'role'    => $message['role'],
                        'content' => $message['content'],
                    ];
                }
            }
        }

        // 新增使用者提示詞
        $userPrompt = $this->getUserPrompt();
        if (!empty($userPrompt)) {
            $messages[] = [
                'role'    => 'user',
                'content' => $userPrompt,
            ];

            $messages[] = [
                'role'    => 'user',
                'content' => $userContent,
            ];
        } else {
            $messages[] = [
                'role'    => 'user',
                'content' => $userContent,
            ];
        }

        return $messages;
    }

    /**
     * 取得模板的系統提示詞.
     */
    abstract public function getSystemPrompt(): string;

    /**
     * 取得模板的使用者提示詞.
     */
    abstract public function getUserPrompt(): string;
}
