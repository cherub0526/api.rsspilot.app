<?php

declare(strict_types=1);

return [
    'default_model' => env('AI_DEFAULT_MODEL', 'openai/gpt-4.1-mini'),

    'chat' => [
        /*
         * 每日提問額度的日界時區。額度在這個時區的 00:00 重置，跟使用者自己的
         * 時區無關（settings 目前沒有 timezone 欄位）。整個服務用同一個值，
         * 換時區只要改這裡。
         */
        'quota_timezone' => env('AI_CHAT_QUOTA_TIMEZONE') ?: env('APP_TIMEZONE', 'UTC'),
    ],

    'openrouter' => [
        'api_key'   => env('OPENROUTER_API_KEY'),
        'base_uri'  => 'https://openrouter.ai/api/v1',
        'site_url'  => env('APP_URL', ''),
        'site_name' => env('APP_NAME', 'Video Assistant'),
    ],
];
