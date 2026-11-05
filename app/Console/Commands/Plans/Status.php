<?php

declare(strict_types=1);

namespace App\Console\Commands\Plans;

use App\Models\Plan;
use Hypervel\Console\Command;

/**
 * 開關方案：inactive 只擋新的訂閱，既有訂閱者照常享有權益、照常續約（hidden-plan-link 規格）。
 * 隱藏方案改成 inactive 後，它的專屬連結也一起失效。
 *
 * 用 saveQuietly() 的理由同 plans:link：StripePlanObserver 只同步名稱與描述。
 */
class Status extends Command
{
    use ResolvesPlan;

    private const array STATUSES = [Plan::STATUS_ACTIVE, Plan::STATUS_INACTIVE];

    protected ?string $signature = 'plans:status
        {plan : 方案 id 或 title（title 必須剛好一筆）}
        {status : active 或 inactive}';

    protected string $description = '開啟（active）或關閉（inactive）方案的新訂閱';

    public function handle(): int
    {
        $status = (string) $this->argument('status');

        if (!in_array($status, self::STATUSES, true)) {
            $this->error('status 只能是 ' . implode(' 或 ', self::STATUSES) . '。');

            return self::FAILURE;
        }

        if (!$plan = $this->resolvePlan((string) $this->argument('plan'))) {
            return self::FAILURE;
        }

        $plan->fill(['status' => $status]);

        if ($plan->isDirty()) {
            $plan->saveQuietly();
        }

        $this->info("方案「{$plan->title}」現在是 {$status}。");

        return self::SUCCESS;
    }
}
