<?php

declare(strict_types=1);

return [
    'daily_digest' => [
        'subject' => ':count new video summaries today',
    ],

    'reset_password' => [
        'subject'       => 'Reset Your Audistilizer Password',
        'greeting'      => 'Hello,',
        'line_1'        => 'We received a request to reset the password for your Audistilizer account. Click the button below to set a new password. If your link has expired, please submit a new request.',
        'action'        => 'Reset Password',
        'line_2'        => 'This link expires in :count minutes',
        'fallback_text' => 'If the button does not work, copy and paste the URL below into your browser:',
        'security_note' => 'If you did not request a password reset, you can ignore this email — your account is safe and your password will not be changed. Audistilizer will never ask for your password by email.',
        'alt_logo'      => 'Audistilizer logo',
        'footer_text'   => '&copy; :year Audistilizer Inc.',
    ],
];
