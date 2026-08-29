<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Psr\Http\Message\ResponseInterface;

/**
 * 發放 JWT 的共用部分。
 *
 * 登入、註冊、換發 token 依路由規則各自是一個 Controller（末段路徑段 → 類別），
 * 但三者回給前端的 token 形狀必須逐字相同——欄位名或 `expires_in` 的單位在其中
 * 一支走樣，前端只會在那一條路徑上壞掉。集中在這裡是為了讓它們不可能分岔。
 */
trait IssuesAccessToken
{
    protected function guard()
    {
        return auth('jwt');
    }

    protected function responseAccessToken(string $token, int $statusCode = 200): ResponseInterface
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
        ], $statusCode);
    }
}
