<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Hypervel\Http\Request;

trait SendsSseHeaders
{
    /**
     * 為 SSE streaming response 建立含 CORS 的 headers。
     *
     * response()->stream() 透過 Swoole socket 直接送出 headers，
     * 發生在 CORS middleware after-phase 之前，所以必須在此手動帶入。
     */
    protected function sseHeaders(Request $request): array
    {
        $origin = $request->header('Origin', '');
        $allowedOrigins = config('cors.allowed_origins', ['*']);

        if (in_array('*', $allowedOrigins)) {
            $corsOrigin = '*';
        } elseif (in_array($origin, $allowedOrigins)) {
            $corsOrigin = $origin;
        } else {
            $corsOrigin = '';
        }

        $headers = [
            'Cache-Control'     => 'no-cache',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        if ($corsOrigin !== '') {
            $headers['Access-Control-Allow-Origin'] = $corsOrigin;
            if ($corsOrigin !== '*') {
                $headers['Vary'] = 'Origin';
            }
        }

        return $headers;
    }
}
