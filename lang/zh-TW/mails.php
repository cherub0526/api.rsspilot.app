<?php

declare(strict_types=1);

return [
    'daily_digest' => [
        'subject' => '今日新增了 :count 部影片摘要',
    ],

    'reset_password' => [
        'subject'       => '重設您的 Audistilizer 密碼',
        'greeting'      => '您好，',
        'line_1'        => '我們收到了重設 Audistilizer 帳號密碼的請求。若您確認此操作，請點擊下方按鈕來設定新密碼。若您的連結已失效，請重新提出申請。',
        'action'        => '重設密碼',
        'line_2'        => '此連結將於 :count 分鐘後失效',
        'fallback_text' => '如果按鈕無法點擊，請複製以下連結貼到瀏覽器：',
        'security_note' => '如果您沒有要求重設密碼，請忽略此郵件，您的帳號依然安全，密碼不會被更改。Audistilizer 絕不會透過電子郵件要求您提供密碼。',
        'alt_logo'      => 'Audistilizer 標誌',
        'footer_text'   => '&copy; :year Audistilizer Inc.',
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
