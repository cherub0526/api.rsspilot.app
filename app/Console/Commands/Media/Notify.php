<?php

declare(strict_types=1);

namespace App\Console\Commands\Media;

use Throwable;
use Carbon\Carbon;
use App\Models\User;
use Hypervel\Console\Command;
use App\Jobs\Media\DailyDigestJob;

class Notify extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'media:notify
        {--user= : Only notify the given user ID}
        {--date= : Digest the media added on this date (Y-m-d) instead of today}';

    /**
     * The console command description.
     */
    protected string $description = 'Queue the daily digest mail for users subscribed to a source with notifications on';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $date = $this->resolveDate();

        if ($date === null) {
            $this->error('Invalid --date, expected a parsable date such as 2026-08-29.');
            return;
        }

        // 只撈至少有一個「開啟通知」的訂閱來源的使用者。哪些影片該進信裡是
        // 每位使用者各自的問題（額度會讓同一個來源在不同人身上收到不同影片），
        // 所以這裡只負責派工，實際的查詢與寄送都留在 job 裡，一位使用者出錯
        // 也不會拖垮整批。
        $query = User::query()
            ->whereNotNull('email')
            ->whereHas('sources', function ($sources): void {
                $sources->where('user_sources.notify', true);
            });

        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        }

        $queued = 0;

        $query->chunkById(100, function ($users) use ($date, &$queued) {
            foreach ($users as $user) {
                DailyDigestJob::dispatch((string) $user->getKey(), $date);
                ++$queued;
            }
        });

        $this->info("Queued {$queued} daily digest(s) for {$date}.");
    }

    /**
     * 產出摘要日期字串；`--date` 無法解析時回傳 null。
     *
     * 日界線一律用應用程式時區——`userables.created_at` 也是以同一個時區寫進
     * 資料庫的，換算過去反而會讓當天最早／最晚加入的影片落在窗口外。
     */
    private function resolveDate(): ?string
    {
        $option = $this->option('date');

        if (!$option) {
            return Carbon::today()->toDateString();
        }

        try {
            return Carbon::parse((string) $option)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
