<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use Throwable;
use App\Models\Media;
use App\Models\Source;
use App\Models\SourceSkill;
use Hypervel\Queue\Queueable;
use InvalidArgumentException;
use Hypervel\Support\Facades\Log;
use App\Services\SourceSkillService;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Queue\Contracts\ShouldBeUnique;
use App\Services\SourceSkill\UnstableOutputException;
use App\Services\SourceSkill\GenerationFailedException;

/**
 * 產生一個頻道、一種語言的 Skill（plan〈後端變更〉job）。
 *
 * 呼叫端（排程 T04、插隊 T05）經 SourceSkillService::dispatchGeneration() 派工，派工前已
 * upsert 好 generating 佔位列；「進行中」看的是資料列，ShouldBeUnique 只是第二道防線。
 *
 * 成功時整份覆蓋；失敗時只改狀態，上一版照樣供應（S13）。
 *
 * log 只記 source_id、語言、例外類別與清洗規則名稱：模型輸出、例外訊息、HTTP response
 * body 都可能帶著影片內容或呼叫端給的字串（SR-1、SR-8）。
 */
class GenerateSourceSkillJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public const QUEUE = 'sources.skill';

    public int $tries = 3;

    /** 大於 worker 的 --timeout（300）乘上重試次數裡實際會等到的時間。 */
    public int $uniqueFor = 1200;

    public function __construct(protected Source $source, protected string $language)
    {
        $this->queue = self::QUEUE;
    }

    public function uniqueId(): string
    {
        return $this->source->getKey() . ':' . $this->language;
    }

    public function handle(SourceSkillService $service): void
    {
        $row = $this->row();

        // 語言是呼叫端給的字串。不是暫時性錯誤，重試也不會好，直接標失敗；
        // 例外訊息帶著那個字串，所以也不交給佇列（佇列會把訊息寫進 log 與 failed_jobs）。
        // 沒有佔位列就代表有人繞過了派工守門，不替那個字串建一列
        if (!$service->isSupportedLanguage($this->language)) {
            if ($row->exists) {
                $this->markFailed($row, InvalidArgumentException::class);
            }

            return;
        }

        // 派工之後到執行之前，情況可能已經變了（摘要被刪、訂閱者都降級）；這不是錯誤，單純不該再產生
        if (!$service->isEligible($this->source)) {
            $this->withdraw($row);

            return;
        }

        $row->forceFill(['started_at' => now()])->save();

        $samples = $service->samples($this->source, $this->language);

        try {
            $raw = $service->generateRaw($this->source, $this->language, $samples);
        } catch (Throwable $e) {
            throw $this->retryable($e::class);
        }
        if (trim($raw) === '') {
            throw $this->retryable('empty output');
        }

        try {
            $media = array_map(fn (array $sample) => $sample['media'], $samples);
            $skill = $service->assemble($this->source, $this->language, $raw, $media);
        } catch (UnstableOutputException $e) {
            // 清洗跑不到穩定代表輸出是刻意構造的，同樣的取材重跑沒有意義
            $this->markFailed($row, $e::class);

            return;
        }

        // 存檔失敗的 QueryException 訊息帶 SQL bindings，也就是整份 skill 內容
        try {
            $this->store($row, $skill, $media, $service->modelName());
        } catch (Throwable $e) {
            throw $this->retryable($e::class);
        }

        if ($skill['sanitized_rules'] !== []) {
            Log::info('Source skill output was sanitized', [
                'source_id'       => $this->source->getKey(),
                'language'        => $this->language,
                'sanitized_rules' => $skill['sanitized_rules'],
            ]);
        }
    }

    /**
     * @param array{name: string, description: string, content: string, references: string} $skill
     * @param list<Media> $media
     */
    private function store(SourceSkill $row, array $skill, array $media, string $model): void
    {
        $row->forceFill([
            'name'         => $skill['name'],
            'description'  => $skill['description'],
            'content'      => $skill['content'],
            'references'   => $skill['references'],
            'video_count'  => count($media),
            'media_ids'    => array_map(fn (Media $item) => $item->getKey(), $media),
            'status'       => SourceSkill::STATUS_COMPLETED,
            'ai_model'     => $model,
            'generated_at' => now(),
            'failed_at'    => null,
        ])->save();
    }

    /**
     * 重試用完。只改狀態，不清掉上一版的內容——讀取端照樣供應舊版（S13）。
     */
    public function failed(?Throwable $e): void
    {
        $this->markFailed($this->row(), $e === null ? null : $e::class);
    }

    private function row(): SourceSkill
    {
        return SourceSkill::query()->firstOrNew([
            'source_id' => $this->source->getKey(),
            'language'  => $this->language,
        ]);
    }

    /** 有成功舊版就回到 completed 繼續供應；沒有就刪掉佔位列，讓讀取端依決策表重新判斷。 */
    private function withdraw(SourceSkill $row): void
    {
        if (!$row->exists) {
            return;
        }

        if ($row->getAttribute('generated_at') !== null) {
            $row->forceFill(['status' => SourceSkill::STATUS_COMPLETED])->save();

            return;
        }

        $row->delete();
    }

    private function markFailed(SourceSkill $row, ?string $reason): void
    {
        $row->forceFill(['status' => SourceSkill::STATUS_FAILED, 'failed_at' => now()])->save();

        Log::warning('Source skill generation failed', [
            'source_id' => $this->source->getKey(),
            'language'  => app(SourceSkillService::class)->isSupportedLanguage($this->language) ? $this->language : null,
            'reason'    => $reason,
        ]);
    }

    private function retryable(string $reason): GenerationFailedException
    {
        Log::warning('Source skill generation attempt failed', [
            'source_id' => $this->source->getKey(),
            'language'  => $this->language,
            'attempt'   => $this->attempts(),
            'reason'    => $reason,
        ]);

        return GenerationFailedException::from($reason);
    }
}
