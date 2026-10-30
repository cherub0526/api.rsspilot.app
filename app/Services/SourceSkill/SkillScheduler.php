<?php

declare(strict_types=1);

namespace App\Services\SourceSkill;

use App\Models\User;
use App\Models\Source;
use App\Models\SourceSkill;
use App\Models\Subscription;
use Hypervel\Support\Facades\DB;
use App\Services\SourceSkillService;
use Hypervel\Support\Facades\Cache;

/**
 * 排程（sources:skills）挑出這一輪要產生的 (頻道, 語言) 組。
 *
 * 合格組：頻道有 skill 方案訂閱者、共用摘要至少 3 支；語言是這些訂閱者 AI 語言的集合。
 * 一組要排入，必須是首次、重試或更新其中之一（見 kindOf）。插隊（GET）只處理請求者自己
 * 的語言，排程負責其他所有人。
 */
class SkillScheduler
{
    public const KIND_FIRST = 'first';

    public const KIND_RETRY = 'retry';

    public const KIND_UPDATE = 'update';

    /** generating 超過這個時間就當成 worker 掉了，可以重派（與 GET 的規則 6 相同） */
    private const GENERATING_TIMEOUT_MINUTES = 15;

    private const FAILED_RETRY_HOURS = 24;

    private const UPDATE_AFTER_DAYS = 7;

    /** 最新取材集合與上一版的 media_ids 差了幾支以上才重產 */
    private const UPDATE_MIN_NEW_VIDEOS = 5;

    public function __construct(private SourceSkillService $service)
    {
    }

    /**
     * 所有該排入的組，依排入順序：首次／重試在更新之前；同類內合格訂閱者多的在前，
     * 再依 source id、語言。
     *
     * @return list<array{source: Source, language: string, kind: string, subscribers: int}>
     */
    public function candidates(): array
    {
        $groups = [];
        foreach ($this->subscriberCounts() as $sourceId => $languages) {
            /** @var null|Source $source */
            $source = Source::query()->where('type', Source::TYPE_YOUTUBE_CHANNEL)->find($sourceId);
            if ($source === null || !$this->hasEnoughSummaries($source)) {
                continue;
            }

            $rows = [];
            foreach (SourceSkill::query()->where('source_id', $sourceId)->get() as $row) {
                $rows[(string) $row->getAttribute('language')] = $row;
            }
            foreach ($languages as $language => $subscribers) {
                $kind = $this->kindOf($source, (string) $language, $rows[$language] ?? null);
                if ($kind !== null) {
                    $groups[] = ['source' => $source, 'language' => (string) $language, 'kind' => $kind, 'subscribers' => $subscribers];
                }
            }
        }

        usort($groups, fn (array $a, array $b) => [$a['kind'] === self::KIND_UPDATE, -$a['subscribers'], (string) $a['source']->getKey(), $a['language']]
            <=> [$b['kind'] === self::KIND_UPDATE, -$b['subscribers'], (string) $b['source']->getKey(), $b['language']]);

        return $groups;
    }

    /**
     * 寫 generating 佔位列再派工。被全域上限擋下時還原佔位列並回 false，呼叫端應停止這一輪。
     *
     * 佔位列只改 status 與 started_at，content 是上一版，重產期間仍可讀取與下載。
     */
    public function queue(Source $source, string $language): bool
    {
        // 與 GET 插隊共用同一把 lock，避免兩邊同時建同一列佔位列
        $lock = Cache::lock("skills:queue:{$source->getKey()}:{$language}", 10);
        if (!$lock->get()) {
            return true;
        }

        try {
            /** @var null|SourceSkill $row */
            $row = SourceSkill::query()->where('source_id', $source->getKey())->where('language', $language)->first();
            $placeholder = $row ?? new SourceSkill();
            $previous = ['status' => $placeholder->getAttribute('status'), 'started_at' => $placeholder->getAttribute('started_at')];
            $placeholder->forceFill([
                'source_id'  => $source->getKey(),
                'language'   => $language,
                'status'     => SourceSkill::STATUS_GENERATING,
                'started_at' => now(),
            ])->save();

            if ($this->service->dispatchGeneration($source, $language)) {
                return true;
            }

            $row === null ? $placeholder->delete() : $placeholder->forceFill($previous)->save();

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * 每個頻道、每種語言有幾位合格訂閱者（方案開放 skill 的有效訂閱，含試用）。
     *
     * @return array<string, array<string, int>> source id → 語言 → 人數
     */
    private function subscriberCounts(): array
    {
        // 先縮小到有有效訂閱的人；沒有訂閱的人退回免費方案，免費方案不開放 skill
        // @phpstan-ignore-next-line scopeActive 是 Eloquent 的 local scope，phpstan 解析不到
        $userIds = Subscription::query()->active()->distinct()->pluck('user_id')->all();

        $counts = [];
        foreach (array_chunk($userIds, 500) as $chunk) {
            foreach (User::query()->whereIn('id', $chunk)->with('setting')->get() as $user) {
                if (!$this->service->isSkillEnabled($user)) {
                    continue;
                }

                $language = $this->service->languageFor($user);
                $sourceIds = DB::table('user_sources')->where('user_id', $user->getKey())->pluck('source_id');
                foreach ($sourceIds as $sourceId) {
                    $counts[(string) $sourceId][$language] = ($counts[(string) $sourceId][$language] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    private function hasEnoughSummaries(Source $source): bool
    {
        return count($this->service->samples($source, User::DEFAULT_AI_LANGUAGE)) >= SourceSkillService::MIN_SHARED_SUMMARIES;
    }

    /** 首次、重試、更新，或 null（不排）。 */
    private function kindOf(Source $source, string $language, ?SourceSkill $row): ?string
    {
        if ($row === null) {
            return self::KIND_FIRST;
        }

        $status = $row->getAttribute('status');
        if ($status === SourceSkill::STATUS_GENERATING) {
            $startedAt = $row->getAttribute('started_at');

            return $startedAt === null || $startedAt->lessThan(now()->subMinutes(self::GENERATING_TIMEOUT_MINUTES))
                ? self::KIND_RETRY
                : null;
        }

        if ($status === SourceSkill::STATUS_FAILED) {
            $failedAt = $row->getAttribute('failed_at');

            return $failedAt === null || $failedAt->lessThanOrEqualTo(now()->subHours(self::FAILED_RETRY_HOURS))
                ? self::KIND_RETRY
                : null;
        }

        return $this->isOutdated($source, $language, $row) ? self::KIND_UPDATE : null;
    }

    /** 上一版滿 7 天，而且目前會取材的影片裡至少 5 支是上一版沒有的。 */
    private function isOutdated(Source $source, string $language, SourceSkill $row): bool
    {
        $generatedAt = $row->getAttribute('generated_at');
        if ($generatedAt === null || $generatedAt->greaterThan(now()->subDays(self::UPDATE_AFTER_DAYS))) {
            return false;
        }

        $latest = array_map(fn (array $sample) => (string) $sample['media']->getKey(), $this->service->samples($source, $language));
        $previous = array_map('strval', (array) $row->getAttribute('media_ids'));

        return count(array_diff($latest, $previous)) >= self::UPDATE_MIN_NEW_VIDEOS;
    }
}
