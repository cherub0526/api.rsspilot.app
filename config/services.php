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
    ],
];
