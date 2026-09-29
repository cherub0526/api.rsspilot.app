<?php

declare(strict_types=1);

use Hypervel\Support\Facades\DB;
use Hypervel\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Advance 的每日提問額度回到 50。
     *
     * 前一支（`2026_09_22_110000`）把它降到 30 是成本決定；這一支是產品決定，
     * 把額度還回去。**不是 revert 前一支**——那一支同時在收 Pro 的 drift，
     * Pro 對齊 20 的部分要留著。
     *
     * 也刻意不回去改前一支的內容：它已經推上去了，改掉會讓跑過的環境與沒跑過的
     * 環境對不起來。兩支疊著跑的結果是 Advance 200/50 → 30 → 50，最終值正確。
     *
     * **這個值超出「成本天花板壓在售價 1.5 倍以內」的判準**：50 題/天在重度使用
     * 下是 1.83x、極端是 2.36x（30 題時分別是 1.07x 與 1.50x）。要在 50 題的前提
     * 下回到判準內，得同時動另外三個旋鈕——推理力度降回 low、`max_tool_calls`
     * 壓到 1、歷史視窗從 10 輪收到 6 輪，合起來是 1.30x / 1.44x。試算見
     * rsspilot.app repo 的 docs/pricing-cost-model.md。
     *
     * 條件帶上 30 是為了冪等，同時避免覆蓋掉有人針對這一列做過的其他調整。
     */
    public function up(): void
    {
        DB::table('plans')
            ->where('title', 'Advance')
            ->where('chat_limit', 30)
            ->update(['chat_limit' => 50]);
    }

    public function down(): void
    {
        DB::table('plans')
            ->where('title', 'Advance')
            ->where('chat_limit', 50)
            ->update(['chat_limit' => 30]);
    }
};
