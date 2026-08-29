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

    protected string $languageCode;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media, string $languageCode = SmartSummaryTemplate::DEFAULT_LANGUAGE_CODE)
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

        // 只認全站共用那一筆：使用者自己的摘要（user_id 有值）不能被這支
        // 排程重跑蓋掉。locale 一律存正規化後的值，否則就跟 settings 那邊的
        // 寫法對不上，Media::summaryFor() 永遠選不到。
        /** @var Summary $summary */
        $summary = $this->media->summaries()->firstOrCreate([
            'user_id' => null,
            'locale'  => ISO6391::normalize((string) $caption->locale),
        ]);

        $template = new SmartSummaryTemplate($this->languageCode);

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
        $text = $this->decode($response);

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
        $locale = $this->media->captions()->where('primary', true)->value('locale');

        /** @var null|Summary $summary */
        $summary = $locale
            ? $this->media->summaries()
                ->whereNull('user_id')
                ->where('locale', ISO6391::normalize((string) $locale))
                ->first()
            : null;

        $this->markFailed($summary);
    }

    /**
     * Decode the JSON the prompt asks for, or null when the response cannot be
     * used.
     *
     * `long_summary.content` is the one field worth failing over — the rest is
     * normalised so a model that omits an optional array does not cost a whole
     * summary. The fenced-block tolerance is deliberate: the prompt forbids
     * code fences, but models add them anyway often enough that discarding an
     * otherwise-good summary over one would be the wrong trade.
     *
     * @return null|array<string, mixed>
     */
    private function decode(string $response): ?array
    {
        $decoded = json_decode($this->stripCodeFence(trim($response)), true);

        if (!is_array($decoded) || !is_string($decoded['long_summary']['content'] ?? null)) {
            return null;
        }

        return [
            'short_summary' => (string) ($decoded['short_summary'] ?? ''),
            'long_summary'  => [
                'content'    => $decoded['long_summary']['content'],
                'key_points' => array_values((array) ($decoded['long_summary']['key_points'] ?? [])),
                'keywords'   => array_values((array) ($decoded['long_summary']['keywords'] ?? [])),
            ],
        ];
    }

    /**
     * Unwrap a ```json … ``` block, leaving anything else untouched.
     */
    private function stripCodeFence(string $response): string
    {
        if (!str_starts_with($response, '```')) {
            return $response;
        }

        // Drop the opening fence with its optional language tag, then the
        // closing one.
        $response = (string) preg_replace('/^```[a-zA-Z]*\R?/', '', $response);

        return rtrim((string) preg_replace('/\R?```$/', '', $response));
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
