<?php

declare(strict_types=1);

namespace App\Services\SourceSkill;

use App\Models\User;
use App\Models\Source;
use App\Models\SourceSkill;
use Hypervel\Support\Facades\Cache;
use App\Services\SourceSkillService;

/**
 * 狀態決策表（plan〈API 契約〉）：由上而下，第一個命中的規則勝出。
 *
 * 規則 10 的插隊是這裡唯一的副作用：先 upsert generating 佔位列再派工；「進行中」只看
 * 資料列，ShouldBeUnique 只是第二道防線。被個人或全域上限擋下時不留下佔位列，否則下一次
 * 請求會把它當成規則 6 的「產生中」。
 */
class SkillStatusResolver
{
    /** generating 超過這麼久沒有結果，就當作 job 已經不在了（規則 6、10）。 */
    private const GENERATING_TIMEOUT_MINUTES = 15;

    public function __construct(private SourceSkillService $service)
    {
    }

    public function resolve(User $user, Source $source, bool $subscribed): SkillStatus
    {
        $language = $this->service->languageFor($user);
        $locked = !$this->service->isSkillEnabled($user);
        $count = $this->service->summarizedCount($source);
        $status = fn (string $status, ?string $reason = null, ?SourceSkill $skill = null) => new SkillStatus(
            $status,
            $reason,
            $locked,
            $skill !== null ? (string) $skill->getAttribute('language') : $language,
            $count,
            $skill
        );

        if ($count < SourceSkillService::MIN_SHARED_SUMMARIES) {
            return $status(SkillStatus::INSUFFICIENT);
        }

        /** @var list<SourceSkill> $rows */
        $rows = SourceSkill::query()->where('source_id', $source->getKey())->get()->all();
        $own = $this->forLanguage($rows, $language);

        if ($locked) {
            $version = $this->lockedVersion($rows, $language);

            return $version === null
                ? $status(SkillStatus::NOT_GENERATED, SkillStatus::REASON_UPGRADE_REQUIRED)
                : $status($this->versionStatus($version), null, $version);
        }

        if ($own !== null && $this->isVersion($own)) {
            return $status($this->versionStatus($own), null, $own);
        }
        if ($own !== null && $this->isGenerating($own)) {
            return $status(SkillStatus::GENERATING);
        }
        // 沒有成功版本的失敗列：GET 不重派，排程在 failed_at 超過 24 小時後重派（規則 7）
        if ($own?->getAttribute('status') === SourceSkill::STATUS_FAILED) {
            return $status(SkillStatus::FAILED);
        }
        if (!$subscribed) {
            return $status(SkillStatus::NOT_GENERATED, SkillStatus::REASON_SUBSCRIBE_REQUIRED);
        }

        return $this->queue($user, $source, $language, $own)
            ? $status(SkillStatus::GENERATING)
            : $status(SkillStatus::NOT_GENERATED, SkillStatus::REASON_DAILY_LIMIT);
    }

    /**
     * 插隊。個人每日上限與全域上限都以 UTC 日期計；被全域上限擋下的那次不算進個人的次數。
     */
    private function queue(User $user, Source $source, string $language, ?SourceSkill $row): bool
    {
        // 同一個人連點兩下：兩個請求都會想建同一列佔位列
        $lock = Cache::lock("skills:queue:{$source->getKey()}:{$language}", 10);
        if (!$lock->get()) {
            return true;
        }

        try {
            $today = now('UTC');
            $key = 'skills:queued:' . $user->getKey() . ':' . $today->format('Y-m-d');
            Cache::add($key, 0, $today->copy()->addDay()->startOfDay());
            if ((int) Cache::increment($key) > (int) config('skills.daily_user_limit')) {
                Cache::decrement($key);

                return false;
            }

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

            Cache::decrement($key);
            $row === null ? $placeholder->delete() : $placeholder->forceFill($previous)->save();

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * 上鎖的人看目錄用：自己的語言 → en → 最新一份（規則 3）。
     *
     * @param list<SourceSkill> $rows
     */
    private function lockedVersion(array $rows, string $language): ?SourceSkill
    {
        $versions = array_values(array_filter($rows, fn (SourceSkill $row) => $this->isVersion($row)));
        usort($versions, fn (SourceSkill $a, SourceSkill $b) => $b->getAttribute('generated_at') <=> $a->getAttribute('generated_at'));

        return $this->forLanguage($versions, $language)
            ?? $this->forLanguage($versions, User::DEFAULT_AI_LANGUAGE)
            ?? $versions[0]
            ?? null;
    }

    /** @param list<SourceSkill> $rows */
    private function forLanguage(array $rows, string $language): ?SourceSkill
    {
        foreach ($rows as $row) {
            if ($row->getAttribute('language') === $language) {
                return $row;
            }
        }

        return null;
    }

    /** 成功版本：產生成功過、有內容。失敗或重產中只改 status，內容與 generated_at 還是上一版的。 */
    private function isVersion(SourceSkill $row): bool
    {
        return $row->getAttribute('generated_at') !== null && (string) $row->getAttribute('content') !== '';
    }

    /** 有版本時的對外狀態：最近一次重產失敗回 failed，其餘（含背景重產中）回 ready。 */
    private function versionStatus(SourceSkill $version): string
    {
        return $version->getAttribute('status') === SourceSkill::STATUS_FAILED ? SkillStatus::FAILED : SkillStatus::READY;
    }

    private function isGenerating(SourceSkill $row): bool
    {
        $startedAt = $row->getAttribute('started_at');

        return $row->getAttribute('status') === SourceSkill::STATUS_GENERATING
            && $startedAt !== null
            && $startedAt->greaterThan(now()->subMinutes(self::GENERATING_TIMEOUT_MINUTES));
    }
}
