<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Exception;
use App\Models\Media;
use App\Models\Caption;
use App\Models\Summary;
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

    protected string $language;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media, string $language = SmartSummaryTemplate::DEFAULT_LANGUAGE)
    {
        $this->media = $media;
        $this->language = $language;

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

        /** @var Summary $summary */
        $summary = $this->media->summaries()->firstOrCreate(['locale' => $caption->locale]);

        $template = new SmartSummaryTemplate($this->language);

        try {
            $markdown = $client->summaryCompletions($template->build($caption->text));
        } catch (VideoTranscriberAuthException) {
            $this->releaseOrFail($summary, self::AUTH_RETRY_DELAY_SECONDS);
            return;
        } catch (Exception) {
            $this->releaseOrFail($summary, self::RETRY_DELAY_SECONDS);
            return;
        }

        // An empty result is a failure, not an empty summary: the endpoint
        // answers occasional 502s with a plain-text body carrying no SSE
        // frames at all, which parses down to nothing. Those clear on their
        // own, so it is worth another attempt rather than giving up.
        if (trim($markdown) === '') {
            $this->releaseOrFail($summary, self::RETRY_DELAY_SECONDS);
            return;
        }

        $summary->fill([
            'text'     => $this->wrap($markdown),
            'status'   => Summary::STATUS_COMPLETED,
            'ai_model' => VideoTranscriberClient::SUMMARY_MODEL,
        ])->save();

        $this->media->fill(['status' => Media::STATUS_SUMMARIZED])->save();
    }

    /**
     * Fit the Markdown into the structure the OpenAI-backed summaries already
     * use, so `SummaryResource` consumers keep reading one shape.
     *
     * Smart Summary produces a single Markdown document rather than the split
     * short/long form, so the sibling fields stay empty; `ai_model` is what
     * tells the two kinds of row apart.
     *
     * @return array<string, mixed>
     */
    private function wrap(string $markdown): array
    {
        return [
            'short_summary' => '',
            'long_summary'  => [
                'content'    => $markdown,
                'key_points' => [],
                'keywords'   => [],
            ],
        ];
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
