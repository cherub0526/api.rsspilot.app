<?php

declare(strict_types=1);

namespace App\Services\SourceSkill;

use App\Models\SourceSkill;

/**
 * GET /v1/sources/{id}/skill 的判斷結果（plan〈狀態決策表〉）。
 */
final class SkillStatus
{
    public const READY = 'ready';

    public const GENERATING = 'generating';

    public const FAILED = 'failed';

    public const INSUFFICIENT = 'insufficient';

    public const NOT_GENERATED = 'not_generated';

    public const REASON_UPGRADE_REQUIRED = 'upgrade_required';

    public const REASON_SUBSCRIBE_REQUIRED = 'subscribe_required';

    public const REASON_DAILY_LIMIT = 'daily_limit';

    /**
     * @param string $language 回應的語言：上鎖時是被挑中那一份的語言，其餘是使用者的 skill 語言
     * @param null|SourceSkill $skill 要呈現的成功版本
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $reason,
        public readonly bool $locked,
        public readonly string $language,
        public readonly int $summarizedCount,
        public readonly ?SourceSkill $skill = null,
    ) {
    }
}
