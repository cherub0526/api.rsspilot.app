<?php

declare(strict_types=1);

return [
    'default_model' => env('AI_DEFAULT_MODEL', 'openai/gpt-4.1-mini'),

    'openrouter' => [
        'api_key'   => env('OPENROUTER_API_KEY'),
        'base_uri'  => 'https://openrouter.ai/api/v1',
        'site_url'  => env('APP_URL', ''),
        'site_name' => env('APP_NAME', 'Video Assistant'),
    ],
];
