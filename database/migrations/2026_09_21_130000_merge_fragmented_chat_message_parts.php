<?php

declare(strict_types=1);

use App\Models\ChatMessage;
use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * 把既有訊息裡碎成一個 token 一列的片段併回一段。
     *
     * 串流是一個 token 一個 chunk，而 `ChatController` 一開始把每個 chunk 都各存
     * 一個片段——實測一則回覆會存成 **771 個片段**。寫入端已經修好
     * （`ChatController::appendPart()`），但既有資料列不會自己變好：
     *
     * - 資料上：一則 AI 回覆膨脹成幾百個物件的 JSON
     * - 畫面上：重播歷史時同一段話被拆成幾百個氣泡與思考區塊
     *
     * 前端讀回來時也會合併一次（`mergeParts()`），所以顯示已經正常；這一支處理的
     * 是資料本身。兩邊都做不是重複：前端那層讓渲染不必假設後端永遠寫對。
     *
     * **只併 text 與 thinking**。工具呼叫與結果各自是獨立的一次動作，併起來就看
     * 不出它查了幾次。合併純粹是把相鄰同型別的 `text` 接起來，內容一個字都不會變。
     *
     * 不可逆：碎片的原始切點沒有保留的價值，也沒有任何東西依賴它。
     */
    public function up(): void
    {
        $mergeable = [ChatMessage::PART_TEXT, ChatMessage::PART_THINKING];

        DB::table('chat_messages')
            ->select(['id', 'parts'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($mergeable): void {
                foreach ($rows as $row) {
                    $parts = json_decode((string) $row->parts, true);

                    if (!is_array($parts) || count($parts) < 2) {
                        continue;
                    }

                    $merged = [];

                    foreach ($parts as $part) {
                        if (!is_array($part) || !isset($part['type'])) {
                            continue;
                        }

                        $lastIndex = array_key_last($merged);

                        if (
                            $lastIndex !== null
                            && in_array($part['type'], $mergeable, true)
                            && $merged[$lastIndex]['type'] === $part['type']
                        ) {
                            $merged[$lastIndex]['text'] .= (string) ($part['text'] ?? '');

                            continue;
                        }

                        $merged[] = $part;
                    }

                    if (count($merged) === count($parts)) {
                        continue;
                    }

                    DB::table('chat_messages')
                        ->where('id', $row->id)
                        ->update([
                            'parts' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // 併回去的片段沒有辦法、也沒有理由還原成原本的切點。
    }
};
