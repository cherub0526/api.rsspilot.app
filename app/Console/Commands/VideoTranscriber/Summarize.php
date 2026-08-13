<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use App\Models\Media;
use Hypervel\Bus\UniqueLock;
use Hypervel\Console\Command;
use App\Jobs\Media\VideoTranscriberSmartSummaryJob;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use App\Services\VideoTranscriber\Prompts\SmartSummaryTemplate;

class Summarize extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:summary
        {--id= : Summarise a specific media by ID, whatever its status}
        {--language= : ISO 639-1 code the summary must be written in, e.g. en or zh-TW}
        {--force : Release the unique job lock before dispatching}';

    /**
     * The console command description.
     */
    protected string $description = 'Queue videotranscriber.ai smart summaries for transcribed media';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $query = Media::query();

        // Naming a media is an explicit manual override, so it is dispatched
        // regardless of status — that is the only way to re-summarise a media
        // that already left `transcribed`, e.g. one stuck on
        // `summarize_failed`.
        if ($id = $this->option('id')) {
            $query->where('id', $id);
        } else {
            $query->where('status', Media::STATUS_TRANSCRIBED);
        }

        $force = (bool) $this->option('force');
        $language = (string) ($this->option('language') ?: SmartSummaryTemplate::DEFAULT_LANGUAGE_CODE);

        $query->chunkById(100, function ($medias) use ($force, $language) {
            foreach ($medias as $media) {
                $job = new VideoTranscriberSmartSummaryJob($media, $language);

                if ($force) {
                    $this->releaseUniqueLock($job);
                }

                $this->info('Queueing summary: ' . $media->title . ' (' . $media->id . ')');

                dispatch($job);
            }
        });
    }

    /**
     * Drop the job's unique lock so the dispatch below is not silently skipped.
     *
     * The lock is only released once the job finishes or fails for good, so a
     * worker that dies mid-run leaves it behind for a whole `uniqueFor` window.
     * While it lingers every dispatch is discarded without a word, and this is
     * the only way to requeue the media before it expires.
     */
    private function releaseUniqueLock(VideoTranscriberSmartSummaryJob $job): void
    {
        (new UniqueLock(app(CacheFactory::class)))->release($job);
    }
}
