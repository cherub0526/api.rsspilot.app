<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use Throwable;
use App\Models\Media;
use App\Models\Caption;
use App\Models\Summary;
use Hypervel\Console\Command;
use App\Services\VideoTranscriber\VideoTranscriberClient;
use App\Services\VideoTranscriber\Prompts\SmartSummaryTemplate;

class Summarize extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:summary
        {--id= : Summarise a specific media by ID, whatever its status}
        {--language=English : The language the summary must be written in}';

    /**
     * The console command description.
     */
    protected string $description = 'Summarise transcribed media through videotranscriber.ai and store the result';

    /**
     * Execute the console command.
     *
     * Unlike the start/fetch commands this does the work inline rather than
     * dispatching a job: the summary arrives in a single request, so there is
     * no polling to hand off to a worker.
     */
    public function handle(VideoTranscriberClient $client): void
    {
        $query = Media::query();

        // Naming a media is an explicit manual override, so it is summarised
        // regardless of status — the only way to redo one that already left
        // `transcribed`, e.g. one stuck on `summarize_failed`.
        if ($id = $this->option('id')) {
            $query->where('id', $id);
        } else {
            $query->where('status', Media::STATUS_TRANSCRIBED);
        }

        $template = new SmartSummaryTemplate((string) $this->option('language'));

        $query->chunkById(100, function ($medias) use ($client, $template) {
            foreach ($medias as $media) {
                $this->summarize($client, $template, $media);
            }
        });
    }

    /**
     * Summarise one media, leaving it on a terminal status either way.
     *
     * Failures are caught per media so one bad response cannot abandon the
     * rest of the batch half-processed.
     */
    private function summarize(
        VideoTranscriberClient $client,
        SmartSummaryTemplate $template,
        Media $media
    ): void {
        /** @var null|Caption $caption */
        $caption = $media->captions()->where('primary', true)->first();

        if (!$caption) {
            $this->error('No primary caption: ' . $media->id);
            $this->markFailed($media);

            return;
        }

        $this->info('Summarizing: ' . $media->title . ' (' . $media->id . ')');

        $media->fill(['status' => Media::STATUS_SUMMARIZING])->save();

        /** @var Summary $summary */
        $summary = $media->summaries()->firstOrCreate(['locale' => $caption->locale]);

        try {
            $markdown = $client->summaryCompletions($template->build($caption->text));
        } catch (Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            $this->markFailed($media, $summary);

            return;
        }

        // An empty result is a failure, not an empty summary: the endpoint
        // answers occasional 502s with a plain-text body that carries no SSE
        // frames at all, which parses down to nothing.
        if (trim($markdown) === '') {
            $this->error('Empty summary returned: ' . $media->id);
            $this->markFailed($media, $summary);

            return;
        }

        $summary->fill([
            'text'     => $this->wrap($markdown),
            'status'   => Summary::STATUS_COMPLETED,
            'ai_model' => VideoTranscriberClient::SUMMARY_MODEL,
        ])->save();

        $media->fill(['status' => Media::STATUS_SUMMARIZED])->save();
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

    private function markFailed(Media $media, ?Summary $summary = null): void
    {
        $summary?->fill(['status' => Summary::STATUS_FAILED])->save();

        $media->fill(['status' => Media::STATUS_SUMMARIZE_FAILED])->save();
    }
}
