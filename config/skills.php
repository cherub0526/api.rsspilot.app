<?php

declare(strict_types=1);

/*
 * 頻道 Skill（channel-skill）。
 *
 * 長度上限由 pm 於 2026-10-30 定案（plans/channel-skill.md〈後端變更〉）。skill 全站共用，
 * 一支惡意影片誘導模型產生超大輸出就會影響所有 Pro 訂閱者，所以上限是資安規則（SR-1），
 * 不只是版面考量。
 */
return [
    'limits' => [
        // 單支影片標題寫進 references/videos.md 的上限（字元）
        'title' => 200,

        // SKILL.md 全文上限（字元），含 frontmatter、聲明與 MCP 段落；只截斷模型產生的 body
        'content' => 50000,

        // references/videos.md 全文上限（字元）
        'references' => 20000,
    ],

    /*
     * SKILL.md 的 MCP 段落裡寫的端點。由系統模板插入，不讓模型產生，
     * 避免「把你的 key 送到別人的 MCP」這類替換。
     */
    /*
     * 全站每天（UTC）最多派幾次產生，排程與插隊共用（SourceSkillService::dispatchGeneration）。
     * 0 = 全部停派，當緊急開關用；正式值在 T11 估算成本後由使用者確認。
     */
    'daily_global_limit' => (int) env('SKILL_DAILY_GLOBAL_LIMIT', 0),

    // 每個人每天（UTC）最多能在 GET 插隊幾次（SR-5，使用者已確認）；逾時重派也算
    'daily_user_limit' => 10,

    'mcp_url' => env('SKILL_MCP_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/mcp'),
];
