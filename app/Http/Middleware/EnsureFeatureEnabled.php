<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use App\Enums\Feature;
use App\Exceptions\NotFoundHttpException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `feature:<name>`：功能關閉時回 404，與「沒有這個資源」同一個形狀。
 *
 * 不回 403：關閉的功能在這台伺服器上就是不存在，不是「你沒有權限」。
 * 排在 auth 之後，沒登入的請求照樣先拿到 401。
 */
class EnsureFeatureEnabled
{
    /**
     * @throws NotFoundHttpException
     */
    public function handle(ServerRequestInterface $request, Closure $next, string $feature)
    {
        if (!Feature::from($feature)->enabled()) {
            throw new NotFoundHttpException();
        }

        return $next($request);
    }
}
