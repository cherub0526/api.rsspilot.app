<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\Sources\Sync;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        $schedule->command(Sync::class)->dailyAt('00:00')
            ->name('sources.sync')->onOneServer();
    }

    public function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
