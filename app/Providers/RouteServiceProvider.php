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

        // MCP 端點單獨掛在根路徑而不是 /v1 底下：使用者要把這個網址貼進第三方
        // 工具的設定，`https://api.rsspilot.app/mcp` 比 `/v1/mcp` 好記也好解釋，
        // 而且它走的是 JSON-RPC，本來就不屬於我們自己那套 REST 的版本線。
        Route::group(
            '/mcp',
            base_path('routes/mcp.php'),
            ['middleware' => 'api', 'as' => 'mcp']
        );

        Route::group(
            '/',
            base_path('routes/web.php'),
            ['middleware' => 'web']
        );
    }
}
