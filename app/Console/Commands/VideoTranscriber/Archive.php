<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use App\Models\Media;
use Hypervel\Bus\UniqueLock;
use Hypervel\Console\Command;
use App\Jobs\Media\VideoTranscriberArchiveJob;
use Hypervel\Cache\Contracts\Factory as CacheFactory;

class Archive extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:archive
        {--id= : Archive the assets of a specific media by ID}
        {--force : Release the unique job lock before dispatching}';

    /**
     * The console command description.
     */
    protected string $description = 'Download a media\'s videotranscriber.ai assets onto S3';

    /**
     * Execute the console command.
     *
     * Deliberately one media at a time, and deliberately not scheduled:
     * VideoTranscriberFetchJob already queues the archiving of anything it
     * transcribes, so this exists for the leftovers — a media transcribed
     * before archiving existed, or one whose downloads gave up. A bulk mode
     * would mean pulling a multi-MB mp3 per media with nothing capping the
     * total, which is a decision to take on purpose rather than by default.
     */
    public function handle(): void
    {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('--id is required.');
            return;
        }

        $media = Media::query()->find($id);

        if (!$media) {
            $this->error('Media not found: ' . $id);
            return;
        }

        $job = new VideoTranscriberArchiveJob($media);

        if ($this->option('force')) {
            $this->releaseUniqueLock($job);
        }

        $this->info('Archiving assets: ' . $media->title . ' (' . $media->id . ')');

        dispatch($job);
    }

    /**
     * Drop the job's unique lock so the dispatch below is not silently skipped.
     *
     * The lock is only released once the job finishes or fails for good, so a
     * worker that dies mid-run leaves it behind for a whole `uniqueFor` window.
     * While it lingers every dispatch is discarded without a word, and this is
     * the only way to requeue the media before it expires.
     */
    private function releaseUniqueLock(VideoTranscriberArchiveJob $job): void
    {
        (new UniqueLock(app(CacheFactory::class)))->release($job);
    }
}
