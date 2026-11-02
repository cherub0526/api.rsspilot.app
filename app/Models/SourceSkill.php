<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

/**
 * 頻道 Skill：把一個來源最新的共用摘要萃取成 Agent Skill（SKILL.md + references/videos.md）。
 *
 * 依 (來源, AI 語言) 全站共用一份。重產失敗時只改 status / failed_at，
 * 上一版的 content 留著繼續供應。
 */
class SourceSkill extends Model
{
    use HasUlids;

    use HasFactory;

    public const STATUS_GENERATING = 'generating';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Agent Skill 規格的 name 上限。 */
    public const NAME_MAX_LENGTH = 64;

    public static array $statusMaps = [
        self::STATUS_GENERATING => '產生中',
        self::STATUS_COMPLETED  => '完成',
        self::STATUS_FAILED     => '失敗',
    ];

    protected ?string $table = 'source_skills';

    protected array $fillable = [
        'source_id',
        'language',
        'name',
        'description',
        'content',
        'references',
        'video_count',
        'media_ids',
        'status',
        'started_at',
        'ai_model',
        'generated_at',
        'failed_at',
    ];

    protected array $casts = [
        'source_id'    => 'string',
        'language'     => 'string',
        'video_count'  => 'integer',
        'media_ids'    => 'array',
        'status'       => 'string',
        'started_at'   => 'datetime',
        'generated_at' => 'datetime',
        'failed_at'    => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'id');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(SourceSkillDownload::class, 'source_skill_id', 'id');
    }
}
