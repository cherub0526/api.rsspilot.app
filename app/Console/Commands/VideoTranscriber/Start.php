<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use App\Models\Media;
use Hypervel\Bus\UniqueLock;
use Hypervel\Console\Command;
use App\Jobs\Media\VideoTranscriberStartJob;
use Hypervel\Cache\Contracts\Factory as CacheFactory;

class Start extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:start
        {--id= : Start transcription for a specific media by ID, whatever its status}
        {--force : Release the unique job lock before dispatching}';

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

        $force = (bool) $this->option('force');

        // 指名 media 是明確的人工覆寫，跟上面略過狀態篩選同樣的理由：這裡也
        // 不套用名額限制。真的滿了，job 自己的閘門會把它退回等待。
        $slots = $id ? PHP_INT_MAX : $this->availableSlots();

        if ($slots <= 0) {
            $this->warn(sprintf(
                'Already at the concurrent limit (%d transcribing). Dispatched nothing.',
                $this->maxConcurrent()
            ));

            return;
        }

        $dispatched = 0;
        $skipped = 0;

        $query->chunkById(100, function ($medias) use ($force, $slots, &$dispatched, &$skipped) {
            foreach ($medias as $media) {
                if ($dispatched >= $slots) {
                    ++$skipped;
                    continue;
                }

                $job = new VideoTranscriberStartJob($media);

                if ($force) {
                    $this->releaseUniqueLock($job);
                }

                $this->info('Starting transcription: ' . $media->title . ' (' . $media->id . ')');

                dispatch($job);
                ++$dispatched;
            }
        });

        // 被擋下的數量一定要講出來，否則「只派了 3 筆」看起來會像只有 3 筆
        // 待處理。下一次執行本指令時會接著派。
        if ($skipped > 0) {
            $this->warn(sprintf(
                'Dispatched %d, skipped %d — the concurrent limit is %d.',
                $dispatched,
                $skipped,
                $this->maxConcurrent()
            ));
        }
    }

    /**
     * 還能再送幾筆。
     *
     * 遠端算的是「處理中的任務」，對應到停在 transcribing 的 media——它們要
     * 等 VideoTranscriberFetchJob 取回結果才會離開這個狀態。超額時對方會回
     * 一個代表滿載的業務代碼，而不是讓請求失敗。
     */
    private function availableSlots(): int
    {
        $transcribing = Media::query()
            ->where('status', Media::STATUS_TRANSCRIBING)
            ->count();

        return $this->maxConcurrent() - $transcribing;
    }

    private function maxConcurrent(): int
    {
        return (int) config('services.videotranscriber.max_concurrent', 5);
    }

    /**
     * Drop the job's unique lock so the dispatch below is not silently skipped.
     *
     * The lock is only released once the job finishes or fails for good, so a
     * worker that dies mid-run leaves it behind for a whole `uniqueFor` window.
     * While it lingers every dispatch is discarded without a word, and this is
     * the only way to requeue the media before it expires.
     */
    private function releaseUniqueLock(VideoTranscriberStartJob $job): void
    {
        (new UniqueLock(app(CacheFactory::class)))->release($job);
    }
}
