<?php

declare(strict_types=1);

namespace App\Auth;

use Throwable;
use App\Models\User;
use Hyperf\Context\Context;
use Hypervel\Auth\Contracts\Authenticatable;
use Hypervel\Auth\Guards\JwtGuard as BaseJwtGuard;

/**
 * 框架的 jwt guard，多一道檢查：簽發時間早於使用者 `tokens_invalid_before` 的 token 視同無效
 * （token-invalidation；做法見 reports/chatgpt-plugin/T02-security-jwt-reset.md）。
 *
 * - 檢查放在解析使用者的同一個地方：使用者本來就要查，不多一次查詢；結果和框架一樣放進
 *   這個請求的 Context，同一個請求後面再呼叫 user() 直接取用。
 * - 被拒絕的 token 和解不開的 token 一樣回 null，401 的內容和沒帶 token 時相同，不透露原因。
 * - 掛 `auth` 的路由（包括 `/v1/auth/refresh`），以及直接呼叫 `auth('jwt')->user()` 的地方都會經過這裡。
 *
 * 除了檢查之外，內容與父類別的 user() 相同；升級框架時要對照。
 */
class JwtGuard extends BaseJwtGuard
{
    public function user(): ?Authenticatable
    {
        $token = $this->parseToken();

        if (Context::has($contextKey = $this->getContextKey($token))) {
            return Context::get($contextKey);
        }

        if (!$token) {
            return null;
        }

        $user = null;

        try {
            $payload = $this->jwtManager->decode($token);
            $sub = $payload['sub'] ?? null;
            $user = $sub ? $this->provider->retrieveById($sub) : null;

            if ($user instanceof User && !$user->acceptsTokenIssuedAt((int) ($payload['iat'] ?? 0))) {
                $user = null;
            }
        } catch (Throwable) {
            $user = null;
        }

        Context::set($contextKey, $user);

        return $user;
    }
}
