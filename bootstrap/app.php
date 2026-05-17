<?php

declare(strict_types=1);
use App\Console\Kernel;
use App\Exceptions\Handler;
use Hypervel\Foundation\Application;
use Hypervel\Context\ApplicationContext;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler;

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Hypervel application instance
| which serves as the "glue" for all the components of Hypervel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new Application();

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed.
|
*/

$app->bind(
    Hypervel\Foundation\Console\Contracts\Kernel::class,
    Kernel::class
);

$app->bind(
    ExceptionHandler::class,
    Handler::class
);

ApplicationContext::setContainer($app);

return $app;
