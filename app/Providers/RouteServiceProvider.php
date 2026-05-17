<?php

declare(strict_types=1);

namespace App\Providers;

use Hypervel\Support\Facades\Route;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider as BaseServiceProvider;

class RouteServiceProvider extends BaseServiceProvider
{
    /**
     * The route files for the application.
     */
    protected array $routes = [
    ];

    public function boot(): void
    {
        parent::boot();

        Route::group(
            '/api',
            base_path('routes/api.php'),
            ['middleware' => 'api', 'as' => 'api']
        );

        Route::group(
            '/v1',
            base_path('routes/v1.php'),
            [
                'middleware' => 'api',
                'as'         => 'api.v1',
            ]
        );

        Route::group(
            '/',
            base_path('routes/web.php'),
            ['middleware' => 'web']
        );
    }
}
