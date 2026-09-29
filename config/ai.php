<?php

declare(strict_types=1);

return [
    /*
     * 沒有在 configs.openrouter_models 指定用途的模型時，退回這個值。
     *
     * 預設是 Auto Router：讓 OpenRouter 依提示內容挑模型，比釘死單一模型更能
     * 跟上型錄變動，也不會因為某家供應商當機就整個用途停擺。價格帶與上限走
     * configs.openrouter_routing（見 App\Utils\AI\OpenRouterRouting）——沒帶
     * cost_tier 時 OpenRouter 大約以 low 帶路由。
     *
     * 要把某個用途釘回單一模型，改 configs 那筆值即可，不必動這裡也不必重啟。
     */
    'default_model' => env('AI_DEFAULT_MODEL', 'openrouter/auto'),

    'chat' => [
        /*
         * 每日提問額度的日界時區。額度在這個時區的 00:00 重置，跟使用者自己的
         * 時區無關（settings 目前沒有 timezone 欄位）。整個服務用同一個值，
         * 換時區只要改這裡。
         *
         * 刻意不回退到 APP_TIMEZONE：那個值是 UTC，是資料儲存用的基準，
         * 拿它當日界會讓主要客群（台灣）的「每日」在早上 8 點重置，而不是午夜。
         */
        'quota_timezone' => env('AI_CHAT_QUOTA_TIMEZONE') ?: 'Asia/Taipei',

        /*
         * 要不要請模型把思考過程一起串出來，以及思考的力度。
         *
         * 只對會思考的模型有效；OpenRouter 對不支援的模型會直接忽略這個參數，
         * 不會報錯（所以走 openrouter/auto 時也可以照送）。設成空字串或 off
         * 就完全不送，回到「只有答案」的行為。
         *
         * **預設 low 是成本決定，不是體驗決定**：推理 token 按 output 計價，
         * 而每日提問額度承保的月上限是 chat_limit × 30（見
         * docs/lore/subscription/business-rules.md）。effort 從 low 調到 high
         * 是直接乘在那個天花板上的，要調就得回去重算方案定價。
         */
        'reasoning_effort' => env('AI_CHAT_REASONING_EFFORT', 'low'),

        /*
         * 送進推論的對話歷史最多保留幾則訊息（一輪問答是 2 則）。0 = 不設限。
         *
         * **這是成本旋鈕，不是體驗旋鈕。** 每一輪都要把摘要與歷史整個重送一遍
         * （OpenRouter 沒有替我們保存對話），所以同一段 session 裡第 i 題的
         * input 是 i 的線性函數，累積下來是平方成長。不設限時，一位每天用滿
         * Advance 額度又只開一段對話的使用者，光對話的月成本就會從 $37 變成
         * $94——見 rsspilot.app repo 的 docs/pricing-cost-model.md。
         *
         * 20 則約等於 10 輪問答。截斷是從舊的那端砍，所以歷史有可能以一則
         * assistant 訊息開頭——OpenAI 相容的 API 接受這種開頭，不必為了湊成對
         * 再多砍一則。
         *
         * 調高之前先算一次：這個值是直接乘在每日提問額度承保的月上限上的。
         */
        'history_window' => (int) env('AI_CHAT_HISTORY_WINDOW', 20),

        /*
         * 讓模型自己上網查資料。
         *
         * 走 OpenRouter 的 `openrouter:web_search` **server tool**——搜尋在
         * OpenRouter 那一側執行完才把結果交回模型，我們不掛工具、不接搜尋供應
         * 商，也沒有 client 端要回應的工具呼叫。2026-09 之前這裡是 Tavily 的
         * client-side 工具，換掉的理由見 docs/lore/prompts/business-rules.md
         * 〈上網查資料是方案權益，而且成本結構跟提問次數不一樣〉。
         *
         * **誰能用是方案決定的，不是這裡**：判準是 plans.agent_enabled（目前只有
         * Advance 開），執行點在 ChatController。這裡只管「怎麼搜」。
         *
         * **不要改用 `plugins: [{id: "web"}]`。** 那個 plugin 每次請求都搜，而
         * server tool 是模型自己決定要不要搜。實際會觸發搜尋的題目大約三成，
         * 換成每題都搜等於把這一項的成本乘上三倍。
         *
         * 四個值都是成本決定：
         *
         * - `engine` / `mode`：parallel 的 turbo 是目前最便宜的一檔（約
         *   $0.001/次；server tool 不指定時預設走 Exa，$0.007/次）。
         * - `max_results`：每一筆結果都會整段變成 input token（約 2,000–4,000
         *   字元），所以它同時是品質與成本的旋鈕。
         * - `max_tool_calls`：**上游預設是 30，這裡一定要自己壓。** 每多一次工具
         *   步驟，模型就要把摘要、歷史與已累積的搜尋結果整個重跑一遍——貴的是
         *   這個，不是搜尋本身那幾毫分。
         */
        'web_search' => [
            'engine'         => env('AI_CHAT_WEB_SEARCH_ENGINE', 'parallel'),
            'mode'           => env('AI_CHAT_WEB_SEARCH_MODE', 'turbo'),
            'max_results'    => (int) env('AI_CHAT_WEB_SEARCH_MAX_RESULTS', 3),
            'max_tool_calls' => (int) env('AI_CHAT_WEB_SEARCH_MAX_TOOL_CALLS', 2),
        ],
    ],

    'openrouter' => [
        'api_key'   => env('OPENROUTER_API_KEY'),
        'base_uri'  => 'https://openrouter.ai/api/v1',
        'site_url'  => env('APP_URL', ''),
        'site_name' => env('APP_NAME', 'Video Assistant'),
    ],
];
