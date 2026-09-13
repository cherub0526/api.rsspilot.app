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
     * @param array<int, array{role: string, content: string, images?: array<int, string>}> $messages
     *                                                                                                依序排列的對話訊息。images 是該回合附上的圖片 URL，實作端自行決定
     *                                                                                                怎麼帶給上游；沒有附圖的回合不會有這個 key。
     * @param null|User $user 用來套用方案的路由設定。**共用產物一律傳 null**——
     *                        心智圖與摘要全站只有一份，沒有「當前使用者」可言，
     *                        用觸發者的方案會讓先產生的人決定所有人拿到的品質。
     * @param null|string $sessionId 把同一段對話的每一輪綁在一起的識別碼。只有
     *                               多輪的路徑才有意義——單次產生的產物沒有「下
     *                               一輪」可以共用快取，傳 null 即可。
     * @return Generator<int, string> 逐段產生的回應文字
     */
    public function stream(
        string $instructions,
        array $messages,
        ?User $user = null,
        ?string $sessionId = null
    ): Generator;
}
