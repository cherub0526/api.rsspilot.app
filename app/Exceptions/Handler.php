<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;
use Hypervel\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected array $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // return json when path start with `api`
        $this->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return str_starts_with($path = $request->path(), 'api')
                && (strlen($path) === 3 || $path[3] === '/');
        });

        $this->reportable(function (Throwable $e) {
        });
    }

    protected function unauthenticated($request, $exception): ResponseInterface
    {
        // Return JSON for requests that expect JSON or for API routes
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => ['Unauthenticated.']], 401);
        }

        return response()->make('Unauthenticated.', 401);
    }
}
