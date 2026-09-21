<?php

declare(strict_types=1);
use App\Models\User;

return [
    'defaults' => [
        'guard'    => 'jwt',
        'provider' => 'users',
    ],
    'guards' => [
        'session' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
        'jwt' => [
            'driver'   => 'jwt',
            'provider' => 'users',
        ],
        /*
         * 使用者自己產生的 API key（personal access token）。
         *
         * 只給 /mcp 那條路用——讓人把 RSSPilot 的資料接到自己的 AI 工具上。
         * 自家前端仍然走 jwt：那是短效、可 refresh 的憑證，跟一把貼在第三方
         * 設定檔裡的長期 key 不是同一種東西，不該共用同一個 guard。
         */
        'sanctum' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => User::class,
        ],
    ],
];
