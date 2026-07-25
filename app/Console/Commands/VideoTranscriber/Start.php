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
        {--id= : Start transcription for a specific media by ID}';

    /**
     * The console command description.
     */
    protected string $description = 'Fetch video info and start videotranscriber.ai transcription for created media';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $query = Media::query()->where('status', Media::STATUS_CREATED);

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $query->chunkById(100, function ($medias) {
            foreach ($medias as $media) {
                $this->info('Starting transcription: ' . $media->title . ' (' . $media->id . ')');

                VideoTranscriberStartJob::dispatch($media);
            }
        });
    }
}
