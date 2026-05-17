<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use App\Http\Controllers\IndexController;

Route::any('/', ['as', 'index', 'uses' => IndexController::class . '@index']);
