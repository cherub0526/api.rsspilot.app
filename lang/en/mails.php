<?php

declare(strict_types=1);

return [
    'daily_digest' => [
        'subject'    => ':count new video summaries today',
        'page_title' => 'RSSPilot — Daily digest',

        // Carbon isoFormat pattern. How a date reads changes with the language,
        // so the pattern follows the locale instead of being hard-coded.
        'date_format' => 'dddd, MMMM D, YYYY',

        'greeting'    => 'Hi :name — today is :date',
        'title'       => ':count new video summaries today',
        'meta_videos' => ':count videos',
        'meta_ready'  => 'AI summaries ready',
        'meta_view'   => 'Ready to read',
        'intro'       => 'The channels you follow published <strong>:count videos</strong> today. We have already generated AI summaries so you can get the gist of each one without watching them in full.',

        'key_points' => 'Key points',
        'video_cta'  => 'Read the full summary →',
        'views'      => ':count views',

        'summary_title' => 'Open the dashboard to see every video',
        'summary_text'  => ':channels channels subscribed · :media summaries so far',
        'summary_cta'   => 'Open Dashboard',

        'link_pricing'       => 'Upgrade',
        'link_terms'         => 'Terms of Service',
        'link_privacy'       => 'Privacy Policy',
        'unsubscribe'        => 'Do not want the daily digest any more? :unsubscribe or :settings.',
        'unsubscribe_action' => 'Unsubscribe',
        'settings_action'    => 'adjust your notification settings',
    ],

    'reset_password' => [
        'subject'       => 'Reset Your RSSPilot Password',
        'greeting'      => 'Hello,',
        'line_1'        => 'We received a request to reset the password for your RSSPilot account. Click the button below to set a new password. If your link has expired, please submit a new request.',
        'action'        => 'Reset Password',
        'line_2'        => 'This link expires in :count minutes',
        'fallback_text' => 'If the button does not work, copy and paste the URL below into your browser:',
        'security_note' => 'If you did not request a password reset, you can ignore this email — your account is safe and your password will not be changed. RSSPilot will never ask for your password by email.',
        'alt_logo'      => 'RSSPilot logo',
        'footer_text'   => '&copy; :year RSSPilot',
    ],

    'verify_email' => [
        'subject'       => 'Your RSSPilot verification code: :code',
        'greeting'      => 'Hello,',
        'line_1'        => 'Enter this code to finish signing up:',
        'line_2'        => 'The code expires in :count minutes.',
        'action'        => 'Verify in the browser',
        'action_hint'   => 'Or use the button below to verify in your browser (on desktop, enter the code above instead):',
        'fallback_text' => 'If the button does not work, copy this link into your browser:',
        'security_note' => 'If you did not sign up for RSSPilot, ignore this email and the code will expire on its own.',
        'alt_logo'      => 'RSSPilot logo',
        'footer_text'   => '&copy; :year RSSPilot',
    ],
];
