<?php

declare(strict_types=1);

namespace App\Console\Commands\Media;

use App\Models\User;
use App\Models\Media;
use Hypervel\Console\Command;
use Hypervel\Support\Facades\DB;
use App\Jobs\Media\CustomSummaryJob;
use App\Services\CustomSummaryService;

/**
 * 找出需要自訂摘要的 (影片, 使用者)，派給 CustomSummaryJob。
 *
 * 挑選條件（全部成立才派）：
 *
 * 1. 影片所屬的來源被某位使用者的自訂提示詞綁定
 * 2. **影片是在綁定之後才進來的**——綁定當下不回頭補舊影片。一個來源可能已有數百
 *    支影片，全部補摘要是一大筆推論成本，而且使用者沒有要求
 * 3. 影片已有非空字幕（沒有字幕就沒有東西可摘要）
 * 4. 這位使用者對這支影片還沒有任何摘要列——不論完成或失敗。失敗的由 job 自己重試，
 *    重試用盡就停；這裡不重派，否則壞掉的影片會每分鐘被派一次
 * 5. 使用者仍訂閱該來源，且方案仍開放自訂摘要
 *
 * 條件 5 在這裡也要檢查，不能只靠 job：job 發現不符合時什麼都不寫，條件 4 就永遠
 * 成立，同一組會被每分鐘重派一次。
 */
class CustomSummaries extends Command
{
    /** 每次最多派幾筆，避免一次湧入大量推論。剩下的下一分鐘再派。 */
    private const BATCH_SIZE = 50;

    protected ?string $signature = 'media:custom-summaries';

    protected string $description = '為綁定自訂提示詞的來源的新影片，派工產生使用者專屬摘要';

    public function handle(CustomSummaryService $service): void
    {
        $bindings = DB::table('custom_prompt_sources as cps')
            ->join('custom_prompts as cp', 'cp.id', '=', 'cps.custom_prompt_id')
            ->whereNull('cp.deleted_at')
            ->select(['cp.user_id', 'cps.source_id', 'cps.created_at as bound_at'])
            ->get();

        $eligibility = [];
        $dispatched = 0;
        $seen = [];

        foreach ($bindings as $binding) {
            $userId = (string) $binding->user_id;

            $eligibility[$userId] ??= $this->isEligible($service, $userId);

            if (!$eligibility[$userId]) {
                continue;
            }

            $media = Media::query()
                ->where('source_id', $binding->source_id)
                ->where('created_at', '>=', $binding->bound_at)
                ->whereHas('captions', fn ($query) => $query->where('text', '!=', ''))
                ->whereDoesntHave('summaries', fn ($query) => $query->where('user_id', $userId))
                ->whereExists(fn ($query) => $query->from('user_sources')
                    ->whereColumn('user_sources.source_id', 'media.source_id')
                    ->where('user_sources.user_id', $userId))
                ->orderBy('created_at')
                ->limit(self::BATCH_SIZE - $dispatched)
                ->get();

            foreach ($media as $item) {
                // 同一人的兩個提示詞綁了同一個來源時，這支影片會被挑到兩次。
                // job 自己會選最近更新的那個提示詞，這裡只要派一次。
                $key = $item->getKey() . ':' . $userId;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                dispatch(new CustomSummaryJob($item, $userId));
                ++$dispatched;
            }

            if ($dispatched >= self::BATCH_SIZE) {
                break;
            }
        }

        $this->info("Dispatched {$dispatched} custom summary job(s).");
    }

    private function isEligible(CustomSummaryService $service, string $userId): bool
    {
        $user = User::query()->find($userId);

        return $user !== null && $service->allowsCustomSummary($user);
    }
}
