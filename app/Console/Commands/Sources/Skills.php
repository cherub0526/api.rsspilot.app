<?php

declare(strict_types=1);

namespace App\Console\Commands\Sources;

use App\Models\AiModel;
use Hypervel\Console\Command;
use App\Services\SourceSkillService;
use App\Services\SourceSkill\SkillScheduler;

/**
 * 每小時挑出要產生或更新的頻道 Skill，派給 GenerateSourceSkillJob。
 *
 * 每次最多排入 skills.batch_size 組，剩下的下一個小時再排：skill 共用一個慢速 worker，
 * 一次湧入會把其他佇列塞住。派工經 SourceSkillService::dispatchGeneration() 的全域每日
 * 上限，擋下時這一輪就停。
 *
 * `--dry-run` 只列出這一輪會排入的組；`--estimate` 估算全部待排組的 token 與金額
 * （T11 用來估成本）。兩者都不派工、不寫 DB、不動每日計數器。
 */
class Skills extends Command
{
    /** 重試上限（GenerateSourceSkillJob::$tries），估最壞情況的金額用 */
    private const MAX_ATTEMPTS = 3;

    protected ?string $signature = 'sources:skills
        {--dry-run : 只列出這一輪會排入的組，不派工}
        {--estimate : 估算全部待排組的 token 與金額，不派工}
        {--model= : 估算用的模型（provider_model），預設為 Skill 實際使用的模型}';

    protected string $description = '為有 Pro 訂閱者的頻道產生或更新頻道 Skill';

    public function handle(SkillScheduler $scheduler, SourceSkillService $service): int
    {
        $candidates = $scheduler->candidates();
        $batch = array_slice($candidates, 0, (int) config('skills.batch_size'));

        if ($this->option('estimate')) {
            $this->estimate($candidates, $service);
        }

        if ($this->option('dry-run') || $this->option('estimate')) {
            foreach ($batch as $group) {
                $this->line("{$group['kind']}\t{$group['source']->getKey()}:{$group['language']}\t{$group['subscribers']} subscribers");
            }
            $this->info(sprintf('This run would queue %d of %d groups.', count($batch), count($candidates)));

            return self::SUCCESS;
        }

        $queued = 0;
        foreach ($batch as $group) {
            if (!$scheduler->queue($group['source'], $group['language'])) {
                break;
            }
            ++$queued;
        }
        $this->info(sprintf('Queued %d of %d groups.', $queued, count($candidates)));

        return self::SUCCESS;
    }

    /** @param list<array{source: \App\Models\Source, language: string, kind: string, subscribers: int}> $candidates */
    private function estimate(array $candidates, SourceSkillService $service): void
    {
        $kinds = array_count_values(array_column($candidates, 'kind'));
        $input = 0;
        foreach ($candidates as $group) {
            $input += $service->estimateInputTokens($group['source'], $group['language']);
        }
        $output = count($candidates) * SourceSkillService::MAX_TOKENS;

        $model = (string) ($this->option('model') ?: $service->modelName());
        /** @var null|AiModel $price */
        $price = AiModel::query()->where('provider_model', $model)->whereNotNull('input_price')->whereNotNull('output_price')->first();

        $this->line(sprintf(
            'Groups: first %d, retry %d, update %d',
            $kinds[SkillScheduler::KIND_FIRST] ?? 0,
            $kinds[SkillScheduler::KIND_RETRY] ?? 0,
            $kinds[SkillScheduler::KIND_UPDATE] ?? 0
        ));
        $this->line(sprintf('Estimated input tokens: %s', number_format($input)));
        $this->line(sprintf('Output token ceiling: %s', number_format($output)));
        $this->line("Model: {$model}");

        if ($price === null) {
            $this->line('Estimated cost: unknown (no price for this model)');

            return;
        }

        $cost = ($input * (float) $price->getAttribute('input_price') + $output * (float) $price->getAttribute('output_price')) / 1_000_000;
        $this->line(sprintf('Estimated cost: $%.4f', $cost));
        $this->line(sprintf('With up to %d attempts each: $%.4f', self::MAX_ATTEMPTS, $cost * self::MAX_ATTEMPTS));
    }
}
