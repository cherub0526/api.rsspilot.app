<?php

declare(strict_types=1);

return [
    'daily_digest' => [
        'subject'    => '今日新增了 :count 部视频摘要',
        'page_title' => 'RSSPilot — 每日摘要通知',

        // Carbon isoFormat 的格式字符串，随语系走，不能写死在程序里。
        'date_format' => 'YYYY 年 M 月 D 日，dddd',

        'greeting'    => '嗨，:name！今天是 :date',
        'title'       => '今日新增了 :count 部视频摘要',
        'meta_videos' => ':count 部视频',
        'meta_ready'  => 'AI 摘要已备妥',
        'meta_view'   => '可立即查看',
        'intro'       => '您订阅的频道今天新增了 <strong>:count 部视频</strong>，我们已自动产出 AI 摘要，让您快速掌握每部视频的重点内容，不必花时间完整观看每一部。',

        'key_points' => '重点摘要',
        'video_cta'  => '查看完整摘要 →',
        'views'      => ':count 次观看',

        'summary_title' => '前往 Dashboard 查看所有视频',
        'summary_text'  => '您的订阅共有 :channels 个频道・已累积 :media 部视频摘要',
        'summary_cta'   => '打开 Dashboard',

        'link_pricing'       => '升级方案',
        'link_terms'         => '服务条款',
        'link_privacy'       => '隐私政策',
        'unsubscribe'        => '不想再收到每日摘要通知？:unsubscribe 或 :settings',
        'unsubscribe_action' => '取消订阅',
        'settings_action'    => '调整通知设置',
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
