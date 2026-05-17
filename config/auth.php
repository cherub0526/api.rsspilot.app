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
    ],
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => User::class,
        ],
    ],
];
