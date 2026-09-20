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
    ],

    'openrouter' => [
        'api_key'   => env('OPENROUTER_API_KEY'),
        'base_uri'  => 'https://openrouter.ai/api/v1',
        'site_url'  => env('APP_URL', ''),
        'site_name' => env('APP_NAME', 'Video Assistant'),
    ],
];
