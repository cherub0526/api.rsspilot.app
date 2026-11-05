<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Auth\JwtGuard;
use Stripe\ApiRequestor;
use Hypervel\JWT\JWTManager;
use Hypervel\Auth\AuthManager;
use App\Observers\UserObserver;
use Hypervel\Support\Facades\Auth;
use App\Observers\StripePlanObserver;
use App\Observers\StripeUserObserver;
use Hypervel\Support\ServiceProvider;
use App\Observers\StripePriceObserver;
use App\Services\SwooleStripeHttpClient;
use App\Database\RetryingPostgresConnector;
use Hyperf\HttpServer\Contract\RequestInterface;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register a per-request Stripe HTTP client to prevent coroutine race
        // conditions in Swoole. The default CurlClient is a process-level
        // singleton that reuses one CURL handle; two concurrent coroutines
        // (e.g. webhook + checkout-session after payment) corrupt each other's
        // handle. SwooleStripeHttpClient creates a fresh CurlClient per call.
        ApiRequestor::setHttpClient(new SwooleStripeHttpClient());

        // jwt guard 換成會檢查 tokens_invalid_before 的版本，其他參數照框架的 createJwtDriver()
        Auth::extend('jwt', function (string $name, array $config) {
            /** @var AuthManager $auth */
            $auth = $this->app->get(AuthManager::class);

            return new JwtGuard(
                $name,
                $auth->createUserProvider($config['provider'] ?? null),
                $this->app->get(JWTManager::class),
                $this->app->get(RequestInterface::class),
                (int) config('jwt.ttl', 120)
            );
        });

        User::observe(UserObserver::class);
        User::observe(StripeUserObserver::class);
        Plan::observe(StripePlanObserver::class);
        Price::observe(StripePriceObserver::class);
        //        Media::observe(MediaObserver::class);
    }

    public function register(): void
    {
        $this->app->bind('db.connector.pgsql', RetryingPostgresConnector::class);
    }
}
