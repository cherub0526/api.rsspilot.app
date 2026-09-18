<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use App\Models\Media;
use Hypervel\Bus\UniqueLock;
use Hypervel\Console\Command;
use App\Jobs\Media\VideoTranscriberSmartSummaryJob;
use Hypervel\Cache\Contracts\Factory as CacheFactory;

class Summarize extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:summary
        {--id= : Summarise a specific media by ID, whatever its status}
        {--language= : ISO 639-1 code to override the summary language, e.g. en or zh-TW; defaults to the caption language}
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

        // 不帶 --language 就交給 job 去跟字幕語系對齊（影片是什麼語言，主摘要就是
        // 什麼語言）。這裡不再預設 en——那會讓中文影片產出英文摘要，卻以字幕語系
        // 存進 summaries.locale，見 VideoTranscriberSmartSummaryJob::languageFor()。
        $language = ((string) $this->option('language')) ?: null;

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
