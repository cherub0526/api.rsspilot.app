<?php

declare(strict_types=1);

return [
    /*
     * 這個專案**只用 sanctum 的 personal access token**，不用它的 SPA cookie 認證。
     *
     * 自家前端（web 與 Electron）走的是 jwt guard，沒有要改；sanctum 存在的理由
     * 只有一個：讓使用者自己產生一把長期有效的 API key，拿去接自己的 AI 工具
     * （見 /mcp 端點）。
     *
     * 因此 `stateful` 與 `guard` 都刻意留空——有值的話，任何帶著 session cookie 的
     * 請求都會被當成已認證，等於給了第二條繞過 JWT 的路。
     */
    'stateful' => [],

    'guard' => [],

    /*
     * token 不自動過期。
     *
     * 這把 key 是使用者貼進第三方工具（Claude Desktop、n8n 之類）的設定裡的，
     * 會過期的話他要定期回來換一次，而且失效時第三方只會回一個看不懂的錯誤。
     * 需要作廢就從設定頁刪掉——那是明確的動作，比靜默過期好解釋。
     */
    'expiration' => null,

    /*
     * token 前綴。
     *
     * GitHub 一類的平台靠前綴做 secret scanning：使用者不小心把 key commit 進
     * 公開 repo 時，掃描器認得出這是一把憑證並通知他。沒有前綴就掃不到。
     */
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'rsp_'),

    /*
     * SPA 認證用的中介層。這裡不走那條路，留空陣列。
     */
    'middleware' => [],

    /*
     * token 查詢快取。
     *
     * MCP 客戶端會頻繁打同一個端點，開著可以少掉每次請求的 token 查詢與
     * last_used_at 寫入。預設關閉：正確性優先，要開再開。
     */
    'cache' => [
        'enabled'                      => env('SANCTUM_CACHE_ENABLED', false),
        'store'                        => env('SANCTUM_CACHE_STORE'),
        'ttl'                          => env('SANCTUM_CACHE_TTL', 3600),
        'prefix'                       => env('SANCTUM_CACHE_PREFIX', 'sanctum'),
        'last_used_at_update_interval' => env('SANCTUM_LAST_USED_UPDATE_INTERVAL', 300),
    ],
];
