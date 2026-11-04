<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Models\User;
use App\Models\Oauth;
use Hypervel\Console\Command;
use Hypervel\Support\Facades\DB;

/**
 * 撤銷舊 Google 登入端點（已刪除，security-hotfix-1 C1）可能已被利用的存取權（SR-2）。
 *
 * 那支端點相信客戶端送來的 Google 身分，任何人都能用別人的 provider_id 登入或預先埋進帳號。
 * 端點刪掉之後，已經拿到的 sanctum API key 不會過期、埋進去的 provider_id 也還在，要另外清。
 *
 * - 只處理明確列出的 user id；名單來自使用者看過清查 SQL 的結果，這支指令不自己找人。
 * - 預設 dry-run，加 `--apply` 才寫入。
 * - 刪掉這些帳號的 sanctum token；有 google oauths 而 provider_id 不一致的，改回 oauths 的值
 *   （那是 Google 在 OAuth callback 驗證過的身分）。沒有 google oauths 的帳號沒有可信的值，provider_id 不動。
 * - JWT 沒有黑名單，要撤銷只能輪換 JWT_SECRET，不在這支指令的範圍。
 * - 輸出不含 email、provider_id 與 token。
 */
class RevokeSocialAccess extends Command
{
    protected ?string $signature = 'security:revoke-social-access
        {userIds* : 要撤銷的 user id（來自清查結果）}
        {--apply : 實際寫入；沒有這個選項時只列出會做什麼}';

    protected string $description = '撤銷指定帳號的 API key，並把被竄改的 Google provider_id 改回 OAuth 驗證過的值';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $ids = array_values(array_unique(array_map('strval', (array) $this->argument('userIds'))));

        $users = User::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        foreach (array_diff($ids, $users->keys()->all()) as $missing) {
            $this->warn("{$missing}：找不到這個帳號，略過");
        }

        $plans = $users->map(fn (User $user) => $this->plan($user));

        foreach ($plans as $id => $plan) {
            $this->line(sprintf(
                '%s：%s%d 個 API key；provider_id %s',
                $id,
                $users[$id]->trashed() ? '（已刪除）' : '',
                $plan['tokens'],
                match (true) {
                    $plan['provider_id'] !== null => '改回 OAuth 驗證過的值',
                    $plan['ambiguous']            => '不改（有多組 google OAuth，需要人工判斷）',
                    default                       => '不改',
                }
            ));
        }

        if (!$apply) {
            $this->warn('dry-run：沒有寫入任何資料。確認後加上 --apply 執行。');

            return 0;
        }

        DB::transaction(function () use ($users, $plans): void {
            foreach ($plans as $id => $plan) {
                $user = $users[$id];
                $user->tokens()->delete();

                if ($plan['provider_id'] !== null) {
                    $user->forceFill(['provider_id' => $plan['provider_id']])->save();
                }
            }
        });

        $this->info(sprintf('已處理 %d 個帳號。', $plans->count()));

        return 0;
    }

    /**
     * @return array{tokens: int, provider_id: ?string, ambiguous: bool}
     */
    private function plan(User $user): array
    {
        $verified = Oauth::query()
            ->where('user_id', $user->id)
            ->where('provider', User::SOCIAL_TYPE_GOOGLE)
            ->distinct()
            ->pluck('provider_id')
            ->all();

        $restore = count($verified) === 1 && $verified[0] !== $user->provider_id ? $verified[0] : null;

        return [
            'tokens'      => $user->tokens()->count(),
            'provider_id' => $restore,
            'ambiguous'   => count($verified) > 1,
        ];
    }
}
