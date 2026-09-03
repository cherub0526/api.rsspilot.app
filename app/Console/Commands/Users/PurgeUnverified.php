<?php

declare(strict_types=1);

namespace App\Console\Commands\Users;

use Carbon\Carbon;
use App\Models\User;
use Hypervel\Console\Command;
use App\Models\EmailVerificationCode;

/**
 * 清除超過期限仍未完成驗證的註冊。
 *
 * 存在的理由不是整理資料，是安全性：有人可以拿別人的 email 搶先註冊並設一組密碼，
 * 真正的擁有者之後用 Google 登入時兩者會合併，而那組密碼會被保留（見前端 repo 的
 * docs/auth-email-migration.md 決定 10）。這支指令把那個接管窗口壓在 24 小時內，
 * **是決定 10 能成立的前提，不要放寬期限**。
 *
 * 只清 local 且未驗證的帳號，而且必須是遷移之後才建立的——遷移時既有使用者已經
 * 全部 grandfather 成已驗證，所以正常情況下掃不到他們；這裡的 social_type 與
 * email_verified_at 兩個條件是雙重保險，避免任何一邊出錯就刪到付費帳號。
 */
class PurgeUnverified extends Command
{
    /** 與決定 11 綁定。改這個數字等於改變帳號接管的曝險窗口。 */
    private const RETENTION_HOURS = 24;

    protected ?string $signature = 'users:purge-unverified {--dry-run : 只列出會被刪的帳號，不實際刪除}';

    protected string $description = '刪除超過 24 小時仍未完成 email 驗證的註冊帳號';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $deadline = Carbon::now()->subHours(self::RETENTION_HOURS);

        $query = User::query()
            ->where('social_type', User::SOCIAL_TYPE_LOCAL)
            ->whereNull('email_verified_at')
            ->where('created_at', '<', $deadline);

        $count = $query->count();

        if ($count === 0) {
            $this->info('沒有需要清除的未驗證帳號。');

            return 0;
        }

        if ($dryRun) {
            $this->warn("dry-run：會刪除 {$count} 個未驗證帳號。");
            $query->get(['id', 'email', 'created_at'])->each(function (User $user): void {
                $this->line("  {$user->id}  {$user->email}  {$user->created_at}");
            });

            return 0;
        }

        $ids = $query->pluck('id')->all();

        EmailVerificationCode::query()->whereIn('user_id', $ids)->delete();
        User::query()->whereIn('id', $ids)->delete();

        $this->info("已清除 {$count} 個未驗證帳號。");

        return 0;
    }
}
