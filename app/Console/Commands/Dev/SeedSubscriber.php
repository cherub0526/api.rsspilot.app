<?php

declare(strict_types=1);

namespace App\Console\Commands\Dev;

use App\Models\Plan;
use App\Models\User;
use App\Models\Price;
use App\Models\Subscription;
use Hypervel\Console\Command;
use Hypervel\Support\Facades\Hash;

/** 本機用：建一個有付費訂閱的測試帳號，方便手動驗證方案管理畫面。 */
class SeedSubscriber extends Command
{
    protected ?string $signature = 'dev:seed-subscriber {--email=ui-test@tests.invalid} {--password=Passw0rd!23} {--canceled : 建成已排定取消的狀態} {--purge : 刪除該測試帳號}';

    protected string $description = '建立（或更新）一個帶付費訂閱的本機測試帳號';

    public function handle(): void
    {
        // 白名單而不是黑名單：這個指令會**刪掉該帳號所有訂閱**並重設密碼，
        // 用 `!= production` 擋的話，任何 APP_ENV 設錯或沒設的環境都會放行。
        if (!in_array((string) env('APP_ENV', ''), ['local', 'testing'], true)) {
            $this->error('只允許在 APP_ENV=local 或 testing 執行。');

            return;
        }

        $email = (string) $this->option('email');
        $password = (string) $this->option('password');

        $user = User::query()->where('email', $email)->first()
            ?? User::factory()->create(['email' => $email]);

        $user->fill(['password' => Hash::make($password), 'email_verified_at' => now()])->save();

        if ($this->option('purge')) {
            foreach ($user->subscriptions()->withTrashed()->get() as $s) {
                $s->forceDelete();
            }
            $user->forceDelete();
            $this->info("已刪除 {$email}");

            return;
        }

        $plan = Plan::query()->where('title', 'Pro')->first();
        $price = $plan?->prices()->where('unit', Price::UNIT_MONTHLY)->first();

        $user->subscriptions()->delete();

        $subscription = $user->subscriptions()->create([
            'plan_id'           => $plan->id,
            'price_id'          => $price->id,
            'payment_method'    => Subscription::PAYMENT_METHOD_CREEM,
            'status'            => Subscription::STATUS_ACTIVE,
            'start_date'        => now()->subDays(10),
            'next_date'         => now()->addDays(20),
            'cancellation_date' => $this->option('canceled') ? now() : null,
        ]);

        $this->info("帳號：{$email}");
        $this->info("密碼：{$password}");
        $this->line("訂閱：{$subscription->id}  status={$subscription->status}  next_date={$subscription->next_date}");
        $this->line('已排定取消：' . ($this->option('canceled') ? '是' : '否'));
    }
}
