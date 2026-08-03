<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use App\Models\Media;
use Hypervel\Console\Command;
use App\Jobs\Media\VideoTranscriberStartJob;

class Start extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:start
        {--id= : Start transcription for a specific media by ID, whatever its status}';

    /**
     * The console command description.
     */
    protected string $description = 'Fetch video info and start videotranscriber.ai transcription for created media';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $query = Media::query();

        // Naming a media is an explicit manual override, so it is dispatched
        // regardless of status — that is the only way to retry a media that
        // already left `created`, e.g. one stuck on `transcribe_failed`.
        if ($id = $this->option('id')) {
            $query->where('id', $id);
        } else {
            $query->where('status', Media::STATUS_CREATED);
        }

        $query->chunkById(100, function ($medias) {
            foreach ($medias as $media) {
                $this->info('Starting transcription: ' . $media->title . ' (' . $media->id . ')');

                VideoTranscriberStartJob::dispatch($media);
            }
        });
    }
}
