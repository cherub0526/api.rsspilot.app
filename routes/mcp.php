<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use App\Http\Controllers\API\Mcp\ServerController;

/*
 * MCP（Model Context Protocol）端點。
 *
 * 認證走 sanctum：使用者在設定頁產生的 API key，以 `Authorization: Bearer <key>`
 * 送進來。**不接受 jwt**——那是自家前端的短效憑證，不該拿去貼在第三方工具的
 * 設定檔裡。
 *
 * 只開 POST。2026-07-28 版的規格拿掉了 GET 串流與 DELETE 終止 session，對這兩
 * 個動詞回 405 是規格建議的做法，也讓舊客戶端知道要改走 POST。
 */
Route::post('/', [
    'as'         => 'server',
    'uses'       => ServerController::class,
    'middleware' => ['auth:sanctum'],
]);
