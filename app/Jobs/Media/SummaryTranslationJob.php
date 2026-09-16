<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Throwable;
use App\Models\Summary;
use App\Utils\AI\Completion;
use App\Utils\Const\ISO6391;
use Hypervel\Queue\Queueable;
use App\Utils\AI\RoutingProfile;
use App\Utils\AI\SummaryPayload;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use App\Services\VideoTranscriber\Prompts\TranslationTemplate;

/**
 * Translate a finished shared summary into one more locale and store it as its
 * own `summaries` row.
 *
 * One job per target locale, not one job for all of them: the Auto Router picks
 * a different model every call and any of them can be busy or ignore the output
 * format, so a single locale failing is routine. Per-locale jobs retry only the
 * locale that failed, where a single job would either re-translate the ones that
 * already succeeded or have to track its own progress.
 *
 * It deliberately owns **no** `media.status`. The pipeline hands off through
 * that column (docs/lore/transcription/business-rules.md), and a media is
 * genuinely `summarized` once the source-language summary exists — a missing
 * translation must not make it look unfinished, because `Media::summaryFor()`
 * already falls back to the shared summary in another locale. The precedent is
 * `VideoTranscriberArchiveJob`, dispatched the same way for the same reason;
 * the same cost applies too: **existing media are never back-filled**, only
 * media summarised from now on get translations.
 */
class SummaryTranslationJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * A model can answer with something other than the JSON it was asked for,
     * and the router can be busy. Both clear on another attempt.
     */
    protected const MAX_ATTEMPTS = 3;

    /**
     * Longer than a transient error needs, because the failure worth riding out
     * here is upstream capacity (a 429 from the router or the provider behind
     * it), which a quick retry only feeds.
     */
    protected const RETRY_DELAY_SECONDS = 180;

    /**
     * Must cover every release() this job can make, otherwise the worker fails
     * the job on the second attempt instead of letting it retry.
     */
    public int $tries = self::MAX_ATTEMPTS;

    public int $uniqueFor = 3600;

    protected Summary $source;

    protected string $locale;

    public function __construct(Summary $source, string $locale)
    {
        $this->source = $source;
        $this->locale = ISO6391::normalize($locale);

        $this->queue = 'media.summary-translation';
    }

    /**
     * Scoped to the source row rather than its media: re-running a summary
     * creates a new row, and its translations must not be blocked by a lock
     * still held for the previous one.
     */
    public function uniqueId(): string
    {
        return $this->source->id . ':' . $this->locale;
    }

    /**
     * The locales a summary in `$sourceLocale` should be translated into.
     *
     * `available_locales` and nothing wider: those are the languages the UI can
     * actually display, and a locale nobody can select only burns tokens.
     *
     * Compared as whole locales, not as languages — `zh-TW` and `zh-CN` are one
     * language to `ISO6391::language()` but two separate summaries here, and a
     * reader of one does not want the other.
     *
     * @return array<int, string>
     */
    public static function targetLocales(string $sourceLocale): array
    {
        $source = ISO6391::normalize($sourceLocale);

        $locales = array_map(
            static fn (string $locale): string => ISO6391::normalize($locale),
            (array) config('app.available_locales')
        );

        return array_values(array_filter(
            array_unique($locales),
            static fn (string $locale): bool => $locale !== $source
        ));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $text = $this->source->text;

        // Nothing to translate. Both cases are reachable without anything being
        // wrong: the summary can have been re-run (and so emptied) between the
        // dispatch and this attempt.
        if (!is_array($text) || $text === []) {
            return;
        }

        $target = $this->target();

        $target->fill(['status' => Summary::STATUS_PROCESSING])->save();

        $payload = (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Purpose layer only, never a plan's routing: a translation is a shared
        // row every reader of this media gets, so there is no "current user"
        // whose plan could own it (docs/lore/prompts/business-rules.md).
        $profile = RoutingProfile::forPurpose(self::class);

        try {
            $response = Completion::make()->completions(
                $profile->model,
                [['role' => 'user', 'content' => (new TranslationTemplate($this->locale))->build($payload)]],
                array_merge(
                    $profile->parameters,
                    // The default 0.7 is for writing, not for translating: this
                    // prompt's whole contract is that nothing but the language
                    // changes, and creativity here shows up as reordered keys
                    // and "improved" structure.
                    ['temperature' => 0.2]
                )
            );
        } catch (Throwable) {
            $this->releaseOrFail($target);
            return;
        }

        $content = $response['choices'][0]['message']['content'] ?? null;

        // `Completion` does not throw on a non-2xx: an error body (a rate limit
        // from the free router, most often) arrives here as an array with no
        // `choices` at all, which this covers alongside a reply that simply is
        // not the JSON the prompt asked for.
        $translated = is_string($content) ? SummaryPayload::decode($content) : null;

        if ($translated === null) {
            $this->releaseOrFail($target);
            return;
        }

        $target->fill([
            'text'   => $translated,
            'status' => Summary::STATUS_COMPLETED,
            // What the router actually picked, not the slug we asked for —
            // `openrouter/auto` resolves to a different model every call, and
            // the resolved name is the only way to tell a bad translation's
            // model apart afterwards.
            'ai_model' => is_string($response['model'] ?? null) ? $response['model'] : $profile->model,
        ])->save();
    }

    /**
     * The queue's last word once the job has failed for good.
     *
     * handle() only guards the API call, so anything else that throws bypasses
     * the failure marking entirely, leaving the target row on `processing` for
     * good — indistinguishable from one still in flight.
     */
    public function failed(?Throwable $e): void
    {
        $this->existingTarget()?->fill(['status' => Summary::STATUS_FAILED])->save();
    }

    /**
     * The row this translation is stored in: the shared summary for the target
     * locale, created on first translation.
     *
     * `user_id` is null for the same reason the source summary's is — one
     * translation per locale serves every reader, and a user's own summary
     * (`user_id` set) is a separate row this must never overwrite.
     */
    protected function target(): Summary
    {
        return Summary::firstOrCreate([
            'media_id' => $this->source->media_id,
            'user_id'  => null,
            'locale'   => $this->locale,
        ]);
    }

    protected function existingTarget(): ?Summary
    {
        return Summary::query()
            ->where('media_id', $this->source->media_id)
            ->whereNull('user_id')
            ->where('locale', $this->locale)
            ->first();
    }

    /**
     * Back off for another attempt, or settle on failure once they run out.
     *
     * The row stays `processing` while attempts remain: reads ask for completed
     * summaries only, so an in-flight row is invisible either way, and leaving
     * it looking failed would be wrong while a retry is still coming.
     */
    protected function releaseOrFail(Summary $target): void
    {
        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            $target->fill(['status' => Summary::STATUS_FAILED])->save();
            return;
        }

        $this->release(self::RETRY_DELAY_SECONDS);
    }
}
