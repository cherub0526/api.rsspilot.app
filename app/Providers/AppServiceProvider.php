<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Observers\PlanObserver;
use App\Observers\UserObserver;
use App\Observers\PriceObserver;
use App\Observers\StripePlanObserver;
use App\Observers\StripePriceObserver;
use App\Observers\StripeUserObserver;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        User::observe(UserObserver::class);
        User::observe(StripeUserObserver::class);
        Plan::observe(PlanObserver::class);
        Plan::observe(StripePlanObserver::class);
        Price::observe(PriceObserver::class);
        Price::observe(StripePriceObserver::class);
        //        Media::observe(MediaObserver::class);
    }

    public function register(): void
    {
    }
}
