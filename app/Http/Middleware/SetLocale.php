<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Throwable;
use App\Models\User;
use Hypervel\Support\Facades\App;
use Hypervel\Support\Facades\Auth;
use Psr\Http\Message\ServerRequestInterface;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * 優先序：使用者保存的設定 > Accept-Language > config('app.locale')。
     * 使用者是明確選過的，瀏覽器送的 Accept-Language 只是猜測，所以前者贏。
     */
    public function handle(ServerRequestInterface $request, Closure $next)
    {
        $locale = $this->getUserLocale() ?? $this->getPreferredLanguage(
            $request->getHeaderLine('Accept-Language')
        );

        if ($locale) {
            App::setLocale($locale);
        }

        return $next($request);
    }

    /**
     * 這是全域 middleware，跑在路由的 auth middleware 之前，所以不能靠
     * $request->user()。jwt guard 會自己從 Authorization header 解 token，
     * 未帶 token 或 token 失效時回 null。
     *
     * 整段包 try/catch 是因為這裡的失敗不該影響請求本身：語系解析不出來
     * 最多是語言不如預期，讓過期的 token 在這裡就拋例外，會把該由 auth
     * middleware 回的 401 變成別的錯誤。
     */
    private function getUserLocale(): ?string
    {
        try {
            $user = Auth::guard('jwt')->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user->uiLocale() : null;
    }

    private function getPreferredLanguage(string $acceptLanguage): ?string
    {
        if ($acceptLanguage === '') {
            return null;
        }

        $availableLanguages = config('app.available_locales');

        foreach (explode(',', $acceptLanguage) as $language) {
            $parts = explode(';', $language);
            $locale = trim($parts[0]);

            if (in_array($locale, $availableLanguages, true)) {
                return $locale;
            }
        }

        return null;
    }
}
