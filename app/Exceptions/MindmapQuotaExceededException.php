<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use App\Services\DailyQuotaSnapshot;
use Psr\Http\Message\ResponseInterface;

/**
 * 當日心智圖產生額度已用盡。
 *
 * 與 ChatQuotaExceededException 分開，是因為前端的兩個面板要顯示不同的引導文案；
 * 共用一個例外就得靠比對訊息字串來分辨，那太脆弱。
 */
class MindmapQuotaExceededException extends Exception
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
            'messages' => ['mindmap' => [__('validators.controllers.mindmap.mindmap_limit_reached')]],
        ], self::$statusCode);

        foreach ($this->snapshot->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
