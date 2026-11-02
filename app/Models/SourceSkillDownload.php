<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;

/**
 * 頻道 Skill 的一次下載或安裝。桌面端不載 GTM，安裝率只能靠這張表計算。
 */
class SourceSkillDownload extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public const KIND_ZIP = 'zip';

    public const KIND_INSTALL = 'install';

    public static array $kindMaps = [
        self::KIND_ZIP     => 'zip 下載',
        self::KIND_INSTALL => '桌面版安裝',
    ];

    protected ?string $table = 'source_skill_downloads';

    protected array $fillable = [
        'user_id',
        'source_skill_id',
        'kind',
    ];

    protected array $casts = [
        'user_id'         => 'string',
        'source_skill_id' => 'string',
        'kind'            => 'string',
        'created_at'      => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function sourceSkill(): BelongsTo
    {
        return $this->belongsTo(SourceSkill::class, 'source_skill_id', 'id');
    }
}
