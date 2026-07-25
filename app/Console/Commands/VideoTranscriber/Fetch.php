<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use App\Models\Media;
use Hypervel\Console\Command;
use App\Jobs\Media\VideoTranscriberFetchJob;

class Fetch extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:fetch
        {--id= : Fetch transcription for a specific media by ID}';

    /**
     * The console command description.
     */
    protected string $description = 'Fetch videotranscriber.ai transcription results and save them as captions';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $query = Media::query()->where('status', Media::STATUS_TRANSCRIBING);

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $query->chunkById(100, function ($medias) {
            foreach ($medias as $media) {
                $this->info('Fetching transcription: ' . $media->title . ' (' . $media->id . ')');

                VideoTranscriberFetchJob::dispatch($media);
            }
        });
    }
}
