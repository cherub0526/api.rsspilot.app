<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect'      => env('FACEBOOK_REDIRECT_URI'),
    ],
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    'rapidapi' => [
        'key' => env('RAPID_API_KEY'),
    ],

    'videotranscriber' => [
        'secret_key' => env('VIDEOTRANSCRIBER_SECRET_KEY'),
        'email'      => env('VIDEOTRANSCRIBER_EMAIL'),
        'password'   => env('VIDEOTRANSCRIBER_PASSWORD'),

        // Business `code` values that mean the session expired. The API answers
        // 200 with a code rather than 401, so the real value can only be filled
        // in once observed; 401/403 are always treated as expired regardless.
        'unauthorized_codes' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('VIDEOTRANSCRIBER_UNAUTHORIZED_CODES', ''))),
            fn (string $code) => $code !== ''
        )),

        // 對方帳號允許的同時轉錄數。超過時 startTranscription 不會失敗，而是
        // 回 busy_codes 裡的代碼。做成設定是因為這是對方的方案限制，升級就會變。
        'max_concurrent' => (int) env('VIDEOTRANSCRIBER_MAX_CONCURRENT', 5),

        // Business `code` values that mean "at capacity, retry later" — 與
        // unauthorized_codes 同樣的處理方式：API 回 200 帶代碼，不是 HTTP 錯誤。
        // 這類代碼代表「現在滿了」而不是「這筆壞了」，必須退回重試而不是標記失敗。
        'busy_codes' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('VIDEOTRANSCRIBER_BUSY_CODES', '164002'))),
            fn (string $code) => $code !== ''
        )),
    ],
];
