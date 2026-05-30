<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use Stripe\ApiRequestor;
use App\Observers\UserObserver;
use App\Observers\StripePlanObserver;
use App\Observers\StripeUserObserver;
use Hypervel\Support\ServiceProvider;
use App\Observers\StripePriceObserver;
use App\Services\SwooleStripeHttpClient;

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

        User::observe(UserObserver::class);
        User::observe(StripeUserObserver::class);
        Plan::observe(StripePlanObserver::class);
        Price::observe(StripePriceObserver::class);
        //        Media::observe(MediaObserver::class);
    }

    public function register(): void
    {
    }
}
