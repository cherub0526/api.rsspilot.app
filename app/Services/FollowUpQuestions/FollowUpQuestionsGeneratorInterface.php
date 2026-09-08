<?php

declare(strict_types=1);

namespace App\Services\FollowUpQuestions;

use App\Models\User;

/**
 * 依前一輪的回答產生延伸問題。
 *
 * 有兩個實作，差別只在推論後端：VideoTranscriberFollowUpQuestions 打
 * videotranscriber.ai 的 summary/completions，NeuronFollowUpQuestions 走
 * NeuronAI（OpenRouter）。提示詞兩者共用，可直接對照產出。
 */
interface FollowUpQuestionsGeneratorInterface
{
    /**
     * @param string $answers 前一輪的回答內容
     * @param string $language 產生問題所用的語言名稱（見 User::aiLanguageName()）
     * @param null|User $user 用來套用方案的路由設定；videotranscriber 那個實作不吃它
     * @return string[] 解析出的問題，依序排列。模型未依格式回應時可能不足 3 題
     */
    public function generate(string $answers, string $language, ?User $user = null): array;
}
