<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use Throwable;
use App\Models\Media;
use App\Models\Summary;
use App\Utils\AI\Completion;
use Hypervel\Queue\Queueable;
use App\Services\Prompts\TemplateFactory;
use Hypervel\Queue\Contracts\ShouldQueue;
use App\Services\Prompts\TemplateCompletionManager;

class SummaryJob implements ShouldQueue
{
    use Queueable;

    protected Media $media;

    /**
     * Create a new job instance.
     */
    public function __construct(Media $media)
    {
        $this->media = $media;

        $this->queue = 'media.summary';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->media->status === Media::STATUS_SUMMARIZED) {
            return;
        }

        $this->media->update(['status' => Media::STATUS_SUMMARIZING]);

        $caption = $this->media->captions()->where('primary', true)->first();

        if (!$caption) {
            $this->media->update(['status' => Media::STATUS_SUMMARIZE_FAILED]);
            return;
        }

        $summary = $this->media->summaries()->firstOrCreate([
            'locale' => $caption->locale,
        ]);

        try {
            $language = 'English';

            $template = TemplateFactory::create('summary', [
                'language' => $language,
            ]);

            $manager = new TemplateCompletionManager(Completion::make(), $template);
            $response = $manager->complete($caption->text);

            $summary->update([
                'text'   => json_decode($response['choices'][0]['message']['content'], true),
                'status' => Summary::STATUS_COMPLETED,
            ]);

            $this->media->update(['status' => Media::STATUS_SUMMARIZED]);
        } catch (Throwable $e) {
            $summary->update(['status' => Summary::STATUS_FAILED]);
        }
    }
}
