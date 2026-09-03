<?php

declare(strict_types=1);

return [
    'daily_digest' => [
        'subject' => '今日新增了 :count 部影片摘要',
    ],

    'reset_password' => [
        'subject'       => '重设您的 RSSPilot 密码',
        'greeting'      => '您好，',
        'line_1'        => '我们收到了重设 RSSPilot 账号密码的请求。若您确认此操作，请点击下方按钮设定新密码。若您的链接已失效，请重新提出申请。',
        'action'        => '重设密码',
        'line_2'        => '此链接将于 :count 分钟后失效',
        'fallback_text' => '如果按钮无法点击，请复制以下链接粘贴到浏览器：',
        'security_note' => '如果您没有要求重设密码，请忽略此邮件，您的账号依然安全，密码不会被更改。RSSPilot 绝不会通过电子邮件要求您提供密码。',
        'alt_logo'      => 'RSSPilot 标志',
        'footer_text'   => '&copy; :year RSSPilot',
    ],

    'verify_email' => [
        'subject'       => '你的 RSSPilot 验证码：:code',
        'greeting'      => '您好，',
        'line_1'        => '请输入以下验证码完成注册：',
        'line_2'        => '此验证码将于 :count 分钟后失效。',
        'action'        => '直接完成验证',
        'action_hint'   => '或点击下方按钮，在浏览器中完成验证（桌面版请改用上方的验证码）：',
        'fallback_text' => '如果按钮无法点击，请复制以下链接粘贴到浏览器：',
        'security_note' => '如果您没有注册 RSSPilot，请忽略此邮件，这组验证码会自动失效。',
        'alt_logo'      => 'RSSPilot 标志',
        'footer_text'   => '&copy; :year RSSPilot',
    ],
];
