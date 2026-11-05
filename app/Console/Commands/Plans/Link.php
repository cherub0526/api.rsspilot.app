<?php

declare(strict_types=1);

namespace App\Console\Commands\Plans;

use App\Models\Plan;
use Hypervel\Support\Str;
use App\Models\Subscription;
use Hypervel\Console\Command;

/**
 * 隱藏方案的專屬連結：把方案設成 hidden、確保有連結碼，印出 `{CLIENT_URL}/pricing?plan=<code>`。
 *
 * - 重複執行結果不變：已經有碼就沿用，只有 `--rotate` 才換（舊碼立刻失效）。
 * - `--public` 把方案改回公開並清掉連結碼。
 * - 把**公開**方案藏起來會讓它從定價頁消失，所以要 `--force`（SR-6）；
 *   不加時印出目前的有效訂閱數讓人判斷，不改任何資料。
 *
 * 寫入用 saveQuietly()：StripePlanObserver 只同步名稱與描述，這裡兩者都沒變，
 * 觸發它只會多一次沒有意義的 Stripe API 呼叫。
 */
class Link extends Command
{
    use ResolvesPlan;

    protected ?string $signature = 'plans:link
        {plan : 方案 id 或 title（title 必須剛好一筆）}
        {--rotate : 換一組新的連結碼，舊連結立刻失效}
        {--public : 改回公開方案並清掉連結碼}
        {--force : 允許把目前公開的方案改成隱藏}';

    protected string $description = '產生（或沿用、更換）隱藏方案的專屬連結';

    public function handle(): int
    {
        if ($this->option('public') && $this->option('rotate')) {
            $this->error('--public 與 --rotate 不能同時使用。');

            return self::FAILURE;
        }

        if (!$plan = $this->resolvePlan((string) $this->argument('plan'))) {
            return self::FAILURE;
        }

        return $this->option('public') ? $this->makePublic($plan) : $this->makeHidden($plan);
    }

    private function makePublic(Plan $plan): int
    {
        $plan->fill(['visibility' => Plan::VISIBILITY_PUBLIC, 'access_code' => null])->saveQuietly();

        $this->info("方案「{$plan->title}」已改回公開，原本的專屬連結已失效。");

        return self::SUCCESS;
    }

    private function makeHidden(Plan $plan): int
    {
        // 先確認連結組得出來，才動資料：CLIENT_URL 沒設定時不能留下一組沒人知道的碼
        $base = rtrim((string) env('CLIENT_URL', ''), '/');

        if ($base === '') {
            $this->error('CLIENT_URL 沒有設定，組不出連結。');

            return self::FAILURE;
        }

        if ($plan->visibility !== Plan::VISIBILITY_HIDDEN && !$this->option('force')) {
            $this->error(sprintf(
                '方案「%s」目前是公開的，改成隱藏後這個方案會從定價頁消失。目前有 %d 筆有效訂閱。確定要改請加 --force。',
                $plan->title,
                $this->activeSubscriptions($plan)
            ));

            return self::FAILURE;
        }

        $plan->visibility = Plan::VISIBILITY_HIDDEN;

        if ($this->option('rotate') || $plan->access_code === null) {
            $plan->access_code = Str::random(32);
        }

        if ($plan->isDirty()) {
            $plan->saveQuietly();
        }

        $this->line("{$base}/pricing?plan={$plan->access_code}");

        if ($plan->status !== Plan::STATUS_ACTIVE) {
            $this->warn('方案目前是 inactive，連結不會生效。要開放訂閱請執行 plans:status ' . $plan->id . ' active。');
        }

        return self::SUCCESS;
    }

    /** 有效訂閱與權益判斷同一套：Subscription::active()。 */
    private function activeSubscriptions(Plan $plan): int
    {
        // @phpstan-ignore method.notFound
        return Subscription::query()->where('plan_id', $plan->id)->active()->count();
    }
}
