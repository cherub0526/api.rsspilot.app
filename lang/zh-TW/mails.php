<?php

declare(strict_types=1);

return [
    'daily_digest' => [
        'subject'    => '今日新增了 :count 部影片摘要',
        'page_title' => 'RSSPilot — 每日摘要通知',

        // Carbon isoFormat 的格式字串。日期的寫法本身就會隨語言換（中文是
        // 「2026 年 9 月 6 日」、英文是「Saturday, September 6, 2026」），
        // 所以格式跟著語系走，不能寫死在程式裡。
        'date_format' => 'YYYY 年 M 月 D 日，dddd',

        'greeting'    => '嗨，:name！今天是 :date',
        'title'       => '今日新增了 :count 部影片摘要',
        'meta_videos' => ':count 部影片',
        'meta_ready'  => 'AI 摘要已備妥',
        'meta_view'   => '可立即查看',
        'intro'       => '您訂閱的頻道今天新增了 <strong>:count 部影片</strong>，我們已自動產出 AI 摘要，讓您快速掌握每部影片的重點內容，不必花時間完整觀看每一部。',

        'key_points' => '重點摘要',
        'video_cta'  => '查看完整摘要 →',
        'views'      => ':count 次觀看',

        'summary_title' => '前往 Dashboard 查看所有影片',
        'summary_text'  => '您的訂閱共有 :channels 個頻道・已累積 :media 部影片摘要',
        'summary_cta'   => '開啟 Dashboard',

        'link_pricing'       => '升級方案',
        'link_terms'         => '服務條款',
        'link_privacy'       => '隱私政策',
        'unsubscribe'        => '不想再收到每日摘要通知？:unsubscribe 或 :settings',
        'unsubscribe_action' => '取消訂閱',
        'settings_action'    => '調整通知設定',
    ],

    'reset_password' => [
        'subject'       => '重設您的 RSSPilot 密碼',
        'greeting'      => '您好，',
        'line_1'        => '我們收到了重設 RSSPilot 帳號密碼的請求。若您確認此操作，請點擊下方按鈕來設定新密碼。若您的連結已失效，請重新提出申請。',
        'action'        => '重設密碼',
        'line_2'        => '此連結將於 :count 分鐘後失效',
        'fallback_text' => '如果按鈕無法點擊，請複製以下連結貼到瀏覽器：',
        'security_note' => '如果您沒有要求重設密碼，請忽略此郵件，您的帳號依然安全，密碼不會被更改。RSSPilot 絕不會透過電子郵件要求您提供密碼。',
        'alt_logo'      => 'RSSPilot 標誌',
        'footer_text'   => '&copy; :year RSSPilot',
    ],

    'verify_email' => [
        'subject'       => '你的 RSSPilot 驗證碼：:code',
        'greeting'      => '您好，',
        'line_1'        => '請輸入以下驗證碼完成註冊：',
        'line_2'        => '此驗證碼將於 :count 分鐘後失效。',
        'action'        => '直接完成驗證',
        'action_hint'   => '或點擊下方按鈕，在瀏覽器中完成驗證（桌面版請改用上方的驗證碼）：',
        'fallback_text' => '如果按鈕無法點擊，請複製以下連結貼到瀏覽器：',
        'security_note' => '如果您沒有註冊 RSSPilot，請忽略此郵件，這組驗證碼會自動失效。',
        'alt_logo'      => 'RSSPilot 標誌',
        'footer_text'   => '&copy; :year RSSPilot',
    ],
];
