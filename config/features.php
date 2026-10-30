<?php

declare(strict_types=1);

/*
 * 全站功能開關，讀取一律走 App\Enums\Feature。
 *
 * 預設全部關閉：關閉是通過 Creem（merchant of record）帳號審核的狀態——使用者只拿得到
 * 我們自己寫的摘要，拿不到原話、影片畫面或打包好的頻道內容。要開哪一項由使用者決定，
 * 在部署環境設對應的 env 為 true。
 *
 * 這是「這台伺服器有沒有這個功能」，不是方案權益：方案旗標（plans.*_enabled）照常生效，
 * 兩者都開才給。
 */
return [
    // 逐字稿原文：GET captions/{id}、字幕檔下載、MCP 的 get_transcript。
    // 摘要、對話等內部用途照常讀字幕，不受影響。
    'transcript' => (bool) env('FEATURE_TRANSCRIPT', false),

    // 播放器截圖：上傳截圖與帶圖提問。
    'screenshot' => (bool) env('FEATURE_SCREENSHOT', false),

    // 頻道 Skill：GET sources/{id}/skill、下載，以及 sources:skills 排程。
    'channel_skill' => (bool) env('FEATURE_CHANNEL_SKILL', false),
];
