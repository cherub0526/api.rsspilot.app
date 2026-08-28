<?php

declare(strict_types=1);

use Swoole\Constant;
use Hyperf\Server\Event;
use Hyperf\Server\Server;
use App\Http\Kernel as HttpKernel;
use Hyperf\Framework\Bootstrap\WorkerExitCallback;
use Hyperf\Framework\Bootstrap\PipeMessageCallback;
use Hyperf\Framework\Bootstrap\WorkerStartCallback;

return [
    'mode'    => SWOOLE_PROCESS,
    'servers' => [
        [
            'name'      => 'http',
            'type'      => Server::SERVER_HTTP,
            'host'      => env('HTTP_SERVER_HOST', '0.0.0.0'),
            // PORT 是雲端平台（Railway 等）注入的慣例名稱，容器要聽在它指定的
            // port 才收得到流量。多這一層 fallback，部署時就不必記得再設一個
            // HTTP_SERVER_PORT——忘記設會讓程式聽在 9501、平台卻往別的 port
            // 送，健康檢查只會回 service unavailable，看不出是 port 對不上。
            //
            // HTTP_SERVER_PORT 仍然優先，本機與 Forge 都不會設 PORT，行為不變。
            'port'      => (int) env('HTTP_SERVER_PORT', env('PORT', 9501)),
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [HttpKernel::class, 'onRequest'],
            ],
        ],
    ],
    'kernels' => [
        'http' => HttpKernel::class,
    ],
    'settings' => [
        'document_root'                      => base_path('public'),
        'enable_static_handler'              => true,
        Constant::OPTION_ENABLE_COROUTINE    => true,
        Constant::OPTION_WORKER_NUM          => env('SERVER_WORKERS_NUMBER', swoole_cpu_num()),
        Constant::OPTION_PID_FILE            => base_path('runtime/hypervel.pid'),
        Constant::OPTION_OPEN_TCP_NODELAY    => true,
        Constant::OPTION_MAX_COROUTINE       => 100000,
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
        Constant::OPTION_MAX_REQUEST         => 100000,
        Constant::OPTION_SOCKET_BUFFER_SIZE  => 2 * 1024 * 1024,
        Constant::OPTION_BUFFER_OUTPUT_SIZE  => 2 * 1024 * 1024,
    ],
    'callbacks' => [
        Event::ON_WORKER_START => [WorkerStartCallback::class, 'onWorkerStart'],
        Event::ON_PIPE_MESSAGE => [PipeMessageCallback::class, 'onPipeMessage'],
        Event::ON_WORKER_EXIT  => [WorkerExitCallback::class, 'onWorkerExit'],
    ],
];
