<?php

declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OAT;

/**
 * 驗證失敗的實際回應。
 *
 * `App\Exceptions\InvalidRequestException::render()` 回的是 422 加上 `messages`
 * 這個鍵（不是 400、也不是 `errors`）——`$statusCode` 與 `render()` 兩處都寫死了。
 * 既有端點多半沿用 Http400，那份與執行期行為對不上；新端點請用這一份。
 */
#[OAT\Response(
    response: 422,
    description: 'Invalid request parameters',
    content: new OAT\JsonContent(
        properties: [
            new OAT\Property(
                property: 'messages',
                type: 'object',
                example: ['redirect' => ['導轉網址為必填。']]
            ),
        ]
    )
)]
class Http422
{
}
