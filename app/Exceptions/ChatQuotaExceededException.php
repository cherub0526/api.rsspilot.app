<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use App\Services\DailyQuotaSnapshot;
use Psr\Http\Message\ResponseInterface;

/**
 * 當日 AI 提問額度已用盡。
 *
 * 不沿用 InvalidRequestException（422）：前端要能只看 status code 就分辨
 * 「額度用盡 → 顯示升級引導」與一般的欄位驗證錯誤，比對 i18n 字串太脆弱。
 */
class ChatQuotaExceededException extends Exception
{
    public static int $statusCode = 429;

    public static string $statusMessage = 'Too Many Requests';

    public function __construct(private readonly DailyQuotaSnapshot $snapshot)
    {
        parent::__construct(self::$statusMessage, self::$statusCode);
    }

    public function render(): ResponseInterface
    {
        $response = response()->json([
            'messages' => ['chat' => [__('validators.controllers.chat.chat_limit_reached')]],
        ], self::$statusCode);

        foreach ($this->snapshot->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
