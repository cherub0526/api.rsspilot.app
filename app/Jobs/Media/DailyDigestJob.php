<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Media;
use App\Models\Summary;
use App\Mail\DailyDigestMail;
use Hypervel\Queue\Queueable;
use Hypervel\Support\Facades\Mail;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Database\Eloquent\Collection;

class DailyDigestJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param string $userId 收信的使用者
     * @param string $date 要彙整的日期（Y-m-d），以應用程式時區為準
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $date,
    ) {
        $this->queue = 'media.notify';
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        $email = (string) ($user?->getAttribute('email') ?? '');

        if (!$user || $email === '') {
            return;
        }

        $videos = $this->collectVideos($user);

        // 當天沒有可寄的影片就不寄空信——排程每天都會跑，多數使用者多數日子
        // 都沒有新片。
        if ($videos->isEmpty()) {
            return;
        }

        Mail::to($email)
            ->locale($user->uiLocale() ?? (string) config('app.locale'))
            ->send(new DailyDigestMail($user, $videos));
    }

    /**
     * 當天要寫進信裡的影片。
     *
     * 三個條件缺一不可：
     *
     * 1. 影片在**該使用者的影片庫**裡（`userables`）且是當天加入的——用
     *    `userables.created_at` 而不是 `media.created_at`，因為 30 天額度會讓
     *    同一個來源的新片只有一部分進得了某位使用者的影片庫，沒進來的不該出現
     *    在信裡。
     * 2. 影片的來源是使用者「有開啟通知」的訂閱來源；手動貼網址加入的影片
     *    （`source_id` 為 null）不在每日摘要的範圍內。
     * 3. 已經有跑完的摘要——信件版型吃的是 `short_summary` 與 `key_points`，
     *    沒摘要就只剩空殼，標題也是「今日新增了 N 部影片摘要」。
     *
     * @return Collection<int, Media>
     */
    private function collectVideos(User $user): Collection
    {
        $sourceIds = $user->sources()
            ->wherePivot('notify', '=', true)
            ->pluck('sources.id')
            ->all();

        if ($sourceIds === []) {
            return new Collection();
        }

        $day = Carbon::parse($this->date);

        return $user->media()
            ->whereIn('media.source_id', $sourceIds)
            ->whereBetween('userables.created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->whereHas('summaries', function ($summaries): void {
                $summaries->where('status', Summary::STATUS_COMPLETED);
            })
            ->with(['source', 'summary'])
            ->orderByDesc('media.published_at')
            ->get();
    }
}
