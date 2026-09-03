<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Psr\Http\Message\ResponseInterface;

class InvalidRequestException extends Exception
{
    public static int $statusCode = 422;

    public static string $statusMessage = 'Bad Request';

    public array $messages = [];

    /**
     * 機器可讀的錯誤代碼，讓前端能分支到特定處理而不必比對訊息文字
     * （訊息是三語系化的，比對文字必然壞掉）。
     *
     * 只有「前端需要做出不同行為」的錯誤才需要它——一般的欄位驗證錯誤
     * 用 messages 就夠了，不要為每個欄位都發明一個代碼。
     */
    public ?string $errorCode = null;

    /**
     * 隨錯誤一起回去的補充資料，會被平鋪到回應根層級
     * （例如驗證碼錯誤時的 attempts_left）。
     *
     * key 不要用 messages / code，那兩個是保留欄位。
     */
    public array $meta = [];

    public function __construct(
        array $messages = [],
        int $code = 422,
        ?string $errorCode = null,
        array $meta = []
    ) {
        $this->messages  = $messages;
        $this->errorCode = $errorCode;
        $this->meta      = $meta;

        parent::__construct(self::$statusMessage, $code);
    }

    /** 只帶錯誤代碼（可附 meta），不帶欄位訊息的捷徑。 */
    public static function withCode(string $errorCode, array $meta = [], array $messages = []): self
    {
        return new self($messages, self::$statusCode, $errorCode, $meta);
    }

    public function render(): ResponseInterface
    {
        $body = ['messages' => $this->messages];

        if ($this->errorCode !== null) {
            $body['code'] = $this->errorCode;
        }

        // meta 先寫入再被保留欄位覆蓋不了——上面兩個 key 已經先佔位
        $body += $this->meta;

        return response()->json($body, self::$statusCode);
    }
}
