<?php

declare(strict_types=1);

namespace App\Utils\AI;

use Generator;
use App\Models\User;

/**
 * 串流式對話推論。
 *
 * 這層刻意不外露底層 SDK 的型別：呼叫端只給角色／內容的純陣列，拿回一串文字片段。
 * 換掉背後的 SDK 時，只有實作類別要動；測試也能直接綁一個假的實作，不必攔 HTTP。
 */
interface ChatStreamerInterface
{
    /**
     * @param string $instructions 系統提示詞
     * @param array<int, array{role: string, content: string}> $messages 依序排列的對話訊息
     * @param null|User $user 用來套用方案的路由設定。**共用產物一律傳 null**——
     *                        心智圖與摘要全站只有一份，沒有「當前使用者」可言，
     *                        用觸發者的方案會讓先產生的人決定所有人拿到的品質。
     * @return Generator<int, string> 逐段產生的回應文字
     */
    public function stream(string $instructions, array $messages, ?User $user = null): Generator;
}
