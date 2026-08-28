<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\Sources\Sync;
use Hypervel\Console\Scheduling\Schedule;
use App\Console\Commands\VideoTranscriber\Fetch;
use App\Console\Commands\VideoTranscriber\Start;
use App\Console\Commands\VideoTranscriber\Summarize;
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

        // 三支都只是把待處理的 media 派成 job，本身很快就結束；每分鐘跑一次
        // 讓新進的影片盡快開始轉錄、轉錄完的盡快取回結果與產生摘要。名額限制
        // 在 Start 指令內部處理，所以這裡不必擔心一直派會超額。
        $schedule->command(Start::class)->everyMinute()
            ->name('videotranscriber.start')->onOneServer()->withoutOverlapping(5);

        $schedule->command(Fetch::class)->everyMinute()
            ->name('videotranscriber.fetch')->onOneServer()->withoutOverlapping(5);

        // 不會無限重派：job 一開工就把 status 從 transcribed 改成 summarizing，
        // 結束時是 summarized 或 summarize_failed，都不再落入這支指令的查詢條件。
        $schedule->command(Summarize::class)->everyMinute()
            ->name('videotranscriber.summary')->onOneServer()->withoutOverlapping(5);
    }

    public function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
