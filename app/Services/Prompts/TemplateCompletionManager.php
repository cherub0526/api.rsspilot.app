<?php

declare(strict_types=1);

namespace App\Services\Prompts;

use App\Models\User;
use App\Utils\AI\Completion;
use App\Utils\AI\RoutingProfile;
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
     * 這次推論屬於哪位使用者；null 代表全站共用的產物。
     *
     * 摘要（SummaryJob）刻意不帶——`summaries.user_id` 是 null、一支影片全站一份，
     * 用觸發者的方案會讓先看到那支影片的人決定所有人拿到的品質。自訂摘要試跑
     * （SummaryPreviewService）則是 per-user，要帶。
     */
    private ?User $user;

    /**
     * 建構函式.
     */
    public function __construct(Completion $completion, TemplateInterface $template, ?User $user = null)
    {
        $this->completion = $completion;
        $this->template = $template;
        $this->user = $user;
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
     * @param string $model 模型名稱（空字串則依模板查 configs.openrouter_models）
     * @param array $additionalParams 額外參數
     * @return array OpenAI 回應
     */
    public function complete(
        string $userContent,
        string $model = '',
        array $additionalParams = []
    ): array {
        $routing = $this->routingFor($model);
        $model = $model === '' ? $routing->model : $model;

        // 建立消息陣列
        $messages = $this->template->buildMessages($userContent, $additionalParams);

        // 合併選項。路由參數（Auto Router 的 cost tier 等）擺最前面，明確傳入的
        // 選項仍然蓋得過它。
        $options = array_merge(
            $routing->parameters,
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
     * @param string $model 模型名稱（空字串則依模板查 configs.openrouter_models）
     */
    public function completeStream(string $userContent, string $model = ''): ResponseInterface
    {
        $routing = $this->routingFor($model);
        $model = $model === '' ? $routing->model : $model;

        $messages = $this->template->buildMessages($userContent);

        return $this->completion->streamCompletions(
            $model,
            $messages,
            array_merge($routing->parameters, $this->options)
        );
    }

    /**
     * 這次要用的路由設定。
     *
     * **呼叫端明講模型時只拿模型、不帶路由參數**：使用者自選了 `claude-opus-5`，
     * 再附上一個 `cost_tier: low` 的 auto-router plugin 是自相矛盾的指示，而且
     * `max_price` 有機會把他自己選的模型擋掉。明講就是明講。
     *
     * 沒明講時吃使用者方案的設定（$user 為 null 就退回用途層）——自訂摘要試跑是
     * per-user 的路徑，不帶使用者的話 Pro 使用者會跟 Free 用到同一個價格帶。
     */
    private function routingFor(string $model): RoutingProfile
    {
        if ($model !== '') {
            return new RoutingProfile($model);
        }

        return RoutingProfile::for($this->template::class, $this->user);
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
