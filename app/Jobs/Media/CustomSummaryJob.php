<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Throwable;
use App\Models\User;
use App\Models\Media;
use App\Models\Summary;
use Hypervel\Queue\Queueable;
use Hypervel\Support\Facades\Log;
use App\Services\CustomSummaryService;
use App\Services\SummaryPreviewService;
use Hypervel\Queue\Contracts\ShouldQueue;
use App\Exceptions\InvalidRequestException;
use Hypervel\Queue\Contracts\ShouldBeUnique;

/**
 * 依使用者的自訂提示詞，為一支影片產生**這個人專屬**的摘要。
 *
 * 與全站共用的摘要（VideoTranscriberSmartSummaryJob）刻意分開：
 *
 * - 寫的是 `summaries.user_id = 使用者` 的那一列，從不碰 `user_id = null` 的共用
 *   摘要，也不改 `media.status`——共用流程的狀態機不該因為某個人的自訂設定而變動。
 * - 走的是試跑同一條 SummaryPreviewService（OpenRouter + 使用者選的模型），試跑
 *   看到的就是之後真的存下來的。
 *
 * 讀取端早已就緒：Media::summaryFor() 先找這個人的摘要，找不到才退回共用那份，
 * 所以產生失敗時使用者看到的是共用摘要，而不是空白。
 *
 * 由 `media:custom-summaries` 排程派工，見 App\Console\Commands\Media\CustomSummaries。
 */
class CustomSummaryJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    protected const MAX_ATTEMPTS = 3;

    protected const RETRY_DELAY_SECONDS = 60;

    public int $tries = self::MAX_ATTEMPTS;

    public int $uniqueFor = 3600;

    public function __construct(protected Media $media, protected string $userId)
    {
        $this->queue = 'media.custom-summary';
    }

    /**
     * 一支影片對一個人只會有一個在排隊或執行中——排程每分鐘都會跑，在 job 建出
     * 摘要列之前的空檔裡，同一組 (影片, 使用者) 可能被挑中第二次。
     */
    public function uniqueId(): string
    {
        return $this->media->getKey() . ':' . $this->userId;
    }

    public function handle(CustomSummaryService $service, SummaryPreviewService $preview): void
    {
        $user = User::query()->find($this->userId);

        // 派工之後到執行之前，情況可能已經變了：帳號刪了、方案降級、取消訂閱該來源、
        // 把提示詞刪了。這些都不是錯誤，單純不該再產生——連摘要列都不建。
        if (!$user || !$service->allowsCustomSummary($user)) {
            return;
        }

        if (!$user->sources()->whereKey($this->media->source_id)->exists()) {
            return;
        }

        $prompt = $service->promptFor($user, $this->media);
        $captions = $service->captionsOf($this->media);

        if (!$prompt || $captions === null) {
            return;
        }

        /** @var Summary $summary */
        $summary = $this->media->summaries()->firstOrCreate([
            'user_id' => $user->getKey(),
            'locale'  => $service->localeFor($user, $this->media),
        ]);

        $providerModel = $service->providerModelFor($user, $prompt->model_id);

        $summary->fill(['status' => Summary::STATUS_PROCESSING])->save();

        try {
            $text = $preview->preview(
                (string) $prompt->content,
                $captions,
                $user->aiLanguageName(),
                $providerModel,
                $user
            );
        } catch (InvalidRequestException) {
            // SummaryPreviewService 把「連線失敗」與「模型沒照格式回」都收斂成這個
            // 例外。兩者多半重試就好，所以退回佇列而不是當場判死。
            $this->releaseOrFail($summary);

            return;
        }

        $summary->fill([
            'text'     => $text,
            'status'   => Summary::STATUS_COMPLETED,
            'ai_model' => $providerModel !== '' ? $providerModel : 'default',
        ])->save();
    }

    /**
     * 最終失敗時把摘要列標成 failed。
     *
     * 讀取端只挑 completed 的摘要，所以 failed 的這列不會蓋掉共用摘要——使用者
     * 看到的是共用版本，而不是一個永遠「處理中」的空白。
     */
    public function failed(?Throwable $e): void
    {
        $this->media->summaries()
            ->where('user_id', $this->userId)
            ->where('status', '!=', Summary::STATUS_COMPLETED)
            ->update(['status' => Summary::STATUS_FAILED]);

        Log::warning('Custom summary failed for good', [
            'media' => $this->media->getKey(),
            'user'  => $this->userId,
            'error' => $e?->getMessage(),
        ]);
    }

    private function releaseOrFail(Summary $summary): void
    {
        if ($this->attempts() < self::MAX_ATTEMPTS) {
            $this->release(self::RETRY_DELAY_SECONDS);

            return;
        }

        $summary->fill(['status' => Summary::STATUS_FAILED])->save();
    }
}
