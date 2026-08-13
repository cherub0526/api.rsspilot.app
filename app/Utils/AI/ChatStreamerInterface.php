<?php

declare(strict_types=1);

namespace App\Utils\AI;

use Generator;

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
     * @return Generator<int, string> 逐段產生的回應文字
     */
    public function stream(string $instructions, array $messages): Generator;
}
