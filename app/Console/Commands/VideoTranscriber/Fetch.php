<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use App\Models\Media;
use Hypervel\Bus\UniqueLock;
use Hypervel\Console\Command;
use App\Jobs\Media\VideoTranscriberFetchJob;
use Hypervel\Cache\Contracts\Factory as CacheFactory;

class Fetch extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:fetch
        {--id= : Fetch transcription for a specific media by ID, whatever its status}
        {--force : Release the unique job lock before dispatching}';

    /**
     * The console command description.
     */
    protected string $description = 'Fetch videotranscriber.ai transcription results and save them as captions';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $query = Media::query();

        // Naming a media is an explicit manual override, so it is dispatched
        // regardless of status — that is the only way to re-fetch a media that
        // already left `transcribing`, e.g. one stuck on `transcribe_failed`.
        if ($id = $this->option('id')) {
            $query->where('id', $id);
        } else {
            $query->where('status', Media::STATUS_TRANSCRIBING);
        }

        $force = (bool) $this->option('force');

        $query->chunkById(100, function ($medias) use ($force) {
            foreach ($medias as $media) {
                $job = new VideoTranscriberFetchJob($media);

                if ($force) {
                    $this->releaseUniqueLock($job);
                }

                $this->info('Fetching transcription: ' . $media->title . ' (' . $media->id . ')');

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
    private function releaseUniqueLock(VideoTranscriberFetchJob $job): void
    {
        (new UniqueLock(app(CacheFactory::class)))->release($job);
    }
}
