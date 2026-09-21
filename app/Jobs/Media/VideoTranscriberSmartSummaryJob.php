<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Exception;
use Throwable;
use App\Models\Media;
use App\Models\Caption;
use App\Models\Summary;
use App\Utils\Const\ISO6391;
use Hypervel\Queue\Queueable;
use App\Utils\AI\SummaryPayload;
use Hypervel\Support\Facades\Log;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use App\Exceptions\VideoTranscriberAuthException;
use App\Services\VideoTranscriber\VideoTranscriberClient;
use App\Services\VideoTranscriber\Prompts\SmartSummaryTemplate;

class VideoTranscriberSmartSummaryJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * Lower than the fetch job's: the summary arrives in one request rather
     * than by polling, so an attempt only repeats to ride out a transient
     * failure, not to wait for work to finish.
     */
    protected const MAX_ATTEMPTS = 3;

    protected const RETRY_DELAY_SECONDS = 60;

    /**
     * Longer than RETRY_DELAY_SECONDS: an unusable account needs someone to
     * fix the credentials, whereas a 502 clears by itself.
     */
    protected const AUTH_RETRY_DELAY_SECONDS = 300;

    /**
     * Must cover every release() this job can make, otherwise the worker fails
     * the job on the second attempt instead of letting it retry.
     */
    public int $tries = self::MAX_ATTEMPTS;

    public int $uniqueFor = 3600;

    protected Media $media;

    /**
     * 摘要要用哪個語言寫。
     *
     * null 代表「跟著字幕走」——影片是什麼語言，主摘要就是什麼語言。給值則是
     * 明確覆寫（`videotranscriber:summary --language=`），重跑成別的語言時用。
     */
    protected ?string $languageCode;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media, ?string $languageCode = null)
    {
        $this->media = $media;
        $this->languageCode = $languageCode;

        $this->queue = 'videotranscriber.smart-summary';
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
    public function handle(VideoTranscriberClient $client): void
    {
        /** @var null|Caption $caption */
        $caption = $this->media->captions()->where('primary', true)->first();

        if (!$caption) {
            $this->markFailed();
            return;
        }

        $this->media->fill(['status' => Media::STATUS_SUMMARIZING])->save();

        $language = $this->languageFor($caption);

        // 只認全站共用那一筆：使用者自己的摘要（user_id 有值）不能被這支
        // 排程重跑蓋掉。locale 一律存正規化後的值，否則就跟 settings 那邊的
        // 寫法對不上，Media::summaryFor() 永遠選不到。
        /** @var Summary $summary */
        $summary = $this->media->summaries()->firstOrCreate([
            'user_id' => null,
            'locale'  => $language,
        ]);

        $template = new SmartSummaryTemplate($language);

        // 時間戳只存在 segments 裡，`text` 是把每段用空白接起來的扁平字串——
        // 餵 `text` 的話 prompt 裡那句「有時間戳就標註」永遠不會生效。舊資料或
        // 其他來源可能沒有 segments，那時退回 `text`，摘要照做只是沒有時間點。
        $transcript = $caption->timestampedTranscript() ?: (string) $caption->text;

        try {
            $response = $client->completions($template->build($transcript));
        } catch (VideoTranscriberAuthException) {
            $this->releaseOrFail($summary, self::AUTH_RETRY_DELAY_SECONDS);
            return;
        } catch (Exception) {
            $this->releaseOrFail($summary, self::RETRY_DELAY_SECONDS);
            return;
        }

        // Covers both an empty body and one that is not the JSON the prompt
        // asked for. The endpoint answers occasional 502s with a plain-text
        // body carrying no SSE frames at all, and a model can always ignore
        // the output format — both clear on a retry, so it is worth another
        // attempt rather than storing something unusable.
        $text = SummaryPayload::decode($response);

        if ($text === null) {
            $this->releaseOrFail($summary, self::RETRY_DELAY_SECONDS);
            return;
        }

        $summary->fill([
            'text'     => $text,
            'status'   => Summary::STATUS_COMPLETED,
            'ai_model' => VideoTranscriberClient::SUMMARY_MODEL,
        ])->save();

        $this->media->fill(['status' => Media::STATUS_SUMMARIZED])->save();

        $this->dispatchTranslations($summary);
    }

    /**
     * Fan the finished summary out to every other UI locale.
     *
     * Dispatched from here rather than picked up by a scheduled command the way
     * the rest of the pipeline is, because translation owns no `media.status`
     * of its own to be selected by — same shape as the `VideoTranscriberFetchJob`
     * → `VideoTranscriberArchiveJob` hand-off, and with the same consequence:
     * a media only ever gets this one chance, there is no back-fill.
     *
     * A failing dispatch must not undo a summary that is already saved, so the
     * loop swallows its own errors: the worst case is a missing translation,
     * and `Media::summaryFor()` falls back to this summary for those readers.
     */
    private function dispatchTranslations(Summary $summary): void
    {
        foreach (SummaryTranslationJob::targetLocales((string) $summary->locale) as $locale) {
            try {
                dispatch(new SummaryTranslationJob($summary, $locale));
            } catch (Throwable $e) {
                Log::warning('failed to dispatch a summary translation', [
                    'summary' => $summary->id,
                    'locale'  => $locale,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The queue's last word once the job has failed for good.
     *
     * handle() only guards the API call, so anything else that throws — a save
     * that the column cannot hold, an `Error` the `catch (Exception)` above
     * does not cover, the DB being unreachable — bypasses markFailed()
     * entirely. Without this hook the job would land in `failed_jobs` while
     * the media sat on `summarizing` for good, looking like it was still
     * being worked on.
     */
    public function failed(?Throwable $e): void
    {
        /** @var null|Caption $caption */
        $caption = $this->media->captions()->where('primary', true)->first();

        // 用跟 handle() 同一套解析：摘要那一列的 locale 是「摘要寫成什麼語言」，
        // 不是字幕的語言，兩邊算法不一致就會找不到要標記失敗的那一列。
        /** @var null|Summary $summary */
        $summary = ($caption || $this->languageCode !== null)
            ? $this->media->summaries()
                ->whereNull('user_id')
                ->where('locale', $this->languageFor($caption))
                ->first()
            : null;

        $this->markFailed($summary);
    }

    /**
     * 這份摘要要寫成哪個語言，也就是它那一列的 `locale`。
     *
     * **這一欄記的是摘要本身的語言，不是字幕的語言。**兩者原本會不一致：指令
     * 的 `--language` 預設是 en，而資料列卻存字幕的語系，於是中文影片會產出一列
     * 標著 `zh-CN`、內容卻是英文的摘要。那不只是標示錯誤——`SummaryTranslationJob`
     * 以這一欄當來源語言展開翻譯目標，會把英文「翻譯」成英文，而真正需要的中文版
     * 永遠不會被產生，因為系統認為它已經存在。
     *
     * 沒有指定時跟著字幕走（影片是什麼語言，主摘要就是什麼語言）；字幕語系不明時
     * 才退回模板的預設值。
     */
    private function languageFor(?Caption $caption): string
    {
        $code = $this->languageCode ?? (string) ($caption?->locale ?? '');

        return $code === ''
            ? SmartSummaryTemplate::DEFAULT_LANGUAGE_CODE
            : ISO6391::normalize($code);
    }

    /**
     * Back off for another attempt, or settle on failure once they run out.
     *
     * The media stays `summarizing` while attempts remain so it is visibly
     * still in flight rather than looking abandoned.
     */
    private function releaseOrFail(Summary $summary, int $delay): void
    {
        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            $this->markFailed($summary);
            return;
        }

        $this->release($delay);
    }

    private function markFailed(?Summary $summary = null): void
    {
        $summary?->fill(['status' => Summary::STATUS_FAILED])->save();

        $this->media->fill(['status' => Media::STATUS_SUMMARIZE_FAILED])->save();
    }
}
