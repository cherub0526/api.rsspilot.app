<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Exception;
use App\Models\Media;
use Hypervel\Support\Str;
use Hypervel\Queue\Queueable;
use Hypervel\Support\Facades\Log;
use App\Models\VideoTranscription;
use App\Services\PublicCaptionGate;
use Hypervel\Queue\Contracts\ShouldQueue;
use App\Services\VideoTranscriber\Outcome;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use App\Exceptions\VideoTranscriberAuthException;
use App\Services\VideoTranscriber\VideoTranscriberClient;
use App\Services\VideoTranscriber\OpenApiTranscriptionProvider;

class VideoTranscriberStartJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /** start_transcription 裡保存 Idempotency-Key 的欄位；`videotranscriber:start --id=` 會清掉它。 */
    public const string IDEMPOTENCY_KEY = 'idempotency_key';

    /**
     * How long to wait before retrying once the shared videotranscriber.ai
     * account turns out to be unusable. Long enough that a broken password can
     * be fixed by hand before the attempts run out.
     */
    protected const int AUTH_RETRY_DELAY_SECONDS = 300;

    protected const int MAX_ATTEMPTS = 12;

    /**
     * 遠端滿載時的重試間隔。比認證那條短很多——等的是別人的轉錄跑完，
     * 通常幾分鐘內就會空出名額。
     */
    protected const int BUSY_RETRY_DELAY_SECONDS = 60;

    /**
     * 公開字幕查不到（Data API 配額用盡、網路錯誤）時的重試間隔。配額是每日重置，
     * 短間隔重試只會一直撞牆；job 用完 retryUntil 後 media 仍留在 created，
     * 下一輪 videotranscriber:start 會再派。
     */
    protected const int CAPTION_CHECK_RETRY_DELAY_SECONDS = 600;

    /**
     * 塞車重試的總時限。刻意壓在 uniqueFor（3600）之內：超過的話唯一鎖會先
     * 過期，同一筆 media 就可能被重複派工。
     */
    protected const int RETRY_UNTIL_SECONDS = 3000;

    /**
     * Must cover every release() this job can make, otherwise the worker fails
     * the job on the second attempt instead of letting it retry.
     *
     * 注意：一旦定義了 retryUntil()，worker 就完全不看這個值
     * （vendor/hypervel/queue/src/Worker.php:563 的 `! $retryUntil &&`）。
     * 保留它是為了留下意圖，實際的次數上限由 releaseForAuthRetry() 自己的
     * attempts() 檢查把關。
     */
    public int $tries = self::MAX_ATTEMPTS;

    public int $uniqueFor = 3600;

    /**
     * 人工指定（`videotranscriber:start --id`）：不看有沒有人收錄。只有那個指令會傳 true，
     * 沒有 HTTP 入口（SR-1）。
     *
     * 刻意不用建構子提升：提升的屬性沒有自己的預設值，部署前已排隊的 job 還原後這裡會是
     * 未初始化，一讀就拋 Error。宣告在這裡，舊 payload 還原後是 false。
     */
    public bool $manual = false;

    protected Media $media;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media, bool $manual = false)
    {
        $this->media = $media;
        $this->manual = $manual;

        $this->queue = 'videotranscriber.start';
    }

    /**
     * 用時間上限取代次數上限。
     *
     * 塞車時要退回重試幾次是無法預先知道的——取決於前面那些轉錄多久跑完。
     * 用次數當預算會讓「排隊太久」被誤判成「這筆轉錄失敗」，而其實什麼都
     * 沒壞。時間上限才貼近「等到有名額為止」的語意。
     */
    public function retryUntil(): int
    {
        return time() + self::RETRY_UNTIL_SECONDS;
    }

    /**
     * The unique lock key, scoped per media so a media row can never have
     * two of this job in the queue (or executing) at the same time.
     */
    public function uniqueId(): string
    {
        return $this->media->id;
    }

    /**
     * Execute the job.
     */
    public function handle(VideoTranscriberClient $client, ?PublicCaptionGate $captionGate = null): void
    {
        $captionGate ??= app(PublicCaptionGate::class);

        // 排在所有外部呼叫之前，每次 release 重試也會再查一次：派工後影片可能
        // 被移出影片庫、收錄者刪了帳號，或來源改回不免費。維持 created、正常
        // 結束，之後有人收錄時下一輪排程會再派（S03、SR-2、SR-4）
        if (!$this->manual && !$this->media->isEligibleForTranscription()) {
            Log::info('Skipped transcription: nobody collects this media.', ['media_id' => $this->media->id]);
            return;
        }

        // 公開字幕閘門排在名額閘門之前：滿載時這支 job 會被 release 很多次，
        // 檢查結果記在 video_detail 裡，重試時就不會每次都再花 50 單位配額。
        if ($captionGate->enabled() && !$this->passesCaptionGate($captionGate)) {
            return;
        }

        // 主動閘門：名額滿了就別浪費 getUrlInfo 與 startTranscription 兩趟
        // 呼叫。下面仍然保留對 busy code 的處理，因為這個計數是本地推估的，
        // 會跟遠端漂移（media 被刪、fetch 沒跑成功都會讓計數卡住）。
        if ($this->transcribingCount() >= $this->maxConcurrent()) {
            $this->release(self::BUSY_RETRY_DELAY_SECONDS);
            return;
        }

        $videoId = $this->media->video_detail['yt:videoId'];
        $url = sprintf('https://www.youtube.com/watch?v=%s', $videoId);

        // 送出時才讀設定，並把 provider 記在 start_transcription，FetchJob 依記錄走完
        $provider = OpenApiTranscriptionProvider::configured();

        if ($provider === OpenApiTranscriptionProvider::NAME) {
            $this->startOpenApi(app(OpenApiTranscriptionProvider::class), $url);
            return;
        }

        if (!OpenApiTranscriptionProvider::isKnown($provider)) {
            // 指令已經擋下不認得的值；這裡防的是排進佇列之後才改壞設定
            Log::error('Unknown videotranscriber provider; nothing was sent.', ['media_id' => $this->media->id]);
            $this->releaseForAuthRetry();
            return;
        }

        try {
            $urlInfo = $client->getUrlInfo($url);
        } catch (VideoTranscriberAuthException) {
            $this->releaseForAuthRetry();
            return;
        } catch (Exception $e) {
            $this->saveTranscription(['url_info' => ['error' => $e->getMessage()]]);
            $this->markTranscribeFailed();
            return;
        }

        $this->saveTranscription(['url_info' => $urlInfo]);

        if (($urlInfo['code'] ?? null) !== 100000) {
            $this->markTranscribeFailed();
            return;
        }

        $data = $urlInfo['data'] ?? [];

        $this->syncDuration($data);

        try {
            $startTranscription = $client->startTranscription([
                'path'       => $url,
                'type'       => $data['type'] ?? 3,
                'audio_time' => $data['audio_time'] ?? 0,
                'file_name'  => $data['title'] ?? '',
            ]);
        } catch (VideoTranscriberAuthException) {
            $this->releaseForAuthRetry();
            return;
        } catch (Exception $e) {
            $this->saveTranscription(['start_transcription' => ['provider' => OpenApiTranscriptionProvider::LEGACY, 'error' => $e->getMessage()]]);
            $this->markTranscribeFailed();
            return;
        }

        $this->saveTranscription(['start_transcription' => ['provider' => OpenApiTranscriptionProvider::LEGACY] + $startTranscription]);

        $code = $startTranscription['code'] ?? null;

        // 滿載是暫時的，不是這筆的問題。標記失敗會讓 media 再也不會自己
        // 回來，只因為當下剛好排在第六個。
        if ($this->isBusyCode($code)) {
            $this->release(self::BUSY_RETRY_DELAY_SECONDS);
            return;
        }

        if ($code !== 100000) {
            $this->markTranscribeFailed();
            return;
        }

        $this->media->fill(['status' => Media::STATUS_TRANSCRIBING])->save();
    }

    /**
     * 經官方 OpenAPI 送出。錯誤對應見 acceptance.feature 的 S08–S11。
     *
     * Idempotency-Key 在送出前就寫進 start_transcription：連線中斷時任務可能已經建立，
     * 下一次送出（release 重試或排程重派）沿用同一個 key，對方會回同一個任務而不是再扣
     * 一次額度（S24）。拿到任務 id 之後，或 `videotranscriber:start --id=` 手動重送時才換新。
     */
    private function startOpenApi(OpenApiTranscriptionProvider $provider, string $url): void
    {
        if ($problem = $provider->configProblem()) {
            // 不記設定值本身（SR-1.3、SR-3.1）
            Log::error('videotranscriber openapi is not configured; nothing was sent.', [
                'media_id' => $this->media->id,
                'provider' => OpenApiTranscriptionProvider::NAME,
                'reason'   => $problem,
            ]);
            $this->releaseForAuthRetry();
            return;
        }

        $submission = [
            'provider'            => OpenApiTranscriptionProvider::NAME,
            self::IDEMPOTENCY_KEY => $this->idempotencyKey(),
        ];
        $this->saveTranscription(['start_transcription' => $submission]);

        $result = $provider->start($url, $submission[self::IDEMPOTENCY_KEY]);

        $this->saveTranscription(['start_transcription' => $submission + $result->record]);

        if ($result->alert) {
            Log::error('videotranscriber openapi rejected the submission.', [
                'media_id' => $this->media->id,
                'provider' => OpenApiTranscriptionProvider::NAME,
                'attempts' => $this->attempts(),
                ...($result->record['error'] ?? []),
            ]);
        }

        match ($result->outcome) {
            Outcome::Ok => $this->media->fill(['status' => Media::STATUS_TRANSCRIBING])->save(),
            Outcome::Busy, Outcome::Pending => $this->release(self::BUSY_RETRY_DELAY_SECONDS),
            Outcome::Unauthorized => $this->releaseForAuthRetry(),
            Outcome::Failed       => $this->markTranscribeFailed(),
        };
    }

    /**
     * 上一次送出還沒拿到任務 id 時沿用它的 key，否則產生新的。key 只含 media id 與亂數，
     * 不含任何機密（SR-1.1）。
     */
    private function idempotencyKey(): string
    {
        $previous = VideoTranscription::query()->where('media_id', $this->media->id)->first()?->getAttribute('start_transcription');
        $previous = is_array($previous) ? $previous : [];
        $key = $previous[self::IDEMPOTENCY_KEY] ?? null;

        if (($previous['provider'] ?? null) === OpenApiTranscriptionProvider::NAME
            && is_string($key)
            && preg_match('/^[\x21-\x7E]{1,128}$/', $key) === 1
            && empty($previous['data']['audio_id'])
        ) {
            return $key;
        }

        return sprintf('rsspilot-media-%s-%s', $this->media->id, strtolower((string) Str::ulid()));
    }

    /**
     * Persist an endpoint's outcome, successful or not.
     *
     * Both columns are written before their `code` is inspected: a rejection is
     * the only explanation for why a media stops where it does, and an `error`
     * key stands in when the call failed before any response body existed.
     * Without it a failure is indistinguishable from a media never started.
     *
     * The auth path deliberately writes nothing — it releases for a retry, so
     * it has no outcome yet and would only overwrite what the next attempt
     * stores.
     */
    private function saveTranscription(array $attributes): void
    {
        VideoTranscription::updateOrCreate(
            ['media_id' => $this->media->id],
            $attributes
        );
    }

    /**
     * Persist the video length returned by url-info onto the media row.
     */
    private function syncDuration(array $data): void
    {
        $duration = (int) ($data['youtube_video_data']['videoInfo']['duration'] ?? 0);

        if ($duration <= 0) {
            $duration = (int) ($data['audio_time'] ?? 0);
        }

        if ($duration <= 0 || $this->media->duration === $duration) {
            return;
        }

        $this->media->fill(['duration' => $duration])->save();
    }

    /**
     * Back off after the token could not be refreshed. The media keeps its
     * current status so it resumes by itself once the account works again,
     * instead of a credential problem burning every queued media.
     */
    private function releaseForAuthRetry(): void
    {
        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            $this->markTranscribeFailed();
            return;
        }

        $this->release(self::AUTH_RETRY_DELAY_SECONDS);
    }

    /**
     * 本地推估的在途轉錄數。遠端的上限算的是「處理中的任務」，而那正好對應
     * 到停在 transcribing 的 media——它們要等 VideoTranscriberFetchJob 取回
     * 結果才會離開這個狀態。
     */
    private function transcribingCount(): int
    {
        return Media::query()
            ->where('status', Media::STATUS_TRANSCRIBING)
            ->count();
    }

    private function maxConcurrent(): int
    {
        return (int) config('services.videotranscriber.max_concurrent', 5);
    }

    private function isBusyCode(mixed $code): bool
    {
        if (!is_numeric($code)) {
            return false;
        }

        $busyCodes = array_map(
            'intval',
            (array) config('services.videotranscriber.busy_codes', [])
        );

        return in_array((int) $code, $busyCodes, true);
    }

    /**
     * 回 false 代表這支 job 到此為止（已標成 no_captions，或已 release 等待重查）。
     */
    private function passesCaptionGate(PublicCaptionGate $captionGate): bool
    {
        $detail = $this->media->getAttribute('video_detail') ?? [];

        if (($detail['public_captions'] ?? null) === true) {
            return true;
        }

        $hasCaptions = $captionGate->hasPublicCaptions((string) $detail['yt:videoId']);

        if ($hasCaptions === null) {
            $this->release(self::CAPTION_CHECK_RETRY_DELAY_SECONDS);
            return false;
        }

        $this->media->fill([
            'video_detail' => [...$detail, 'public_captions' => $hasCaptions],
            ...($hasCaptions ? [] : ['status' => Media::STATUS_NO_CAPTIONS]),
        ])->save();

        return $hasCaptions;
    }

    private function markTranscribeFailed(): void
    {
        $this->media->fill(['status' => Media::STATUS_TRANSCRIBE_FAILED])->save();
    }
}
