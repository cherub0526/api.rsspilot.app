<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * 一位使用者在一個額度日內產生心智圖的次數。
 *
 * 形狀與用途完全比照 ChatUsage（含「不記 plan_id」的理由）；兩張表分開是因為
 * 心智圖與對話是兩個獨立的額度桶子。
 *
 * @property string $user_id
 * @property string $quota_date
 * @property int $count
 */
class MindmapUsage extends Model
{
    protected ?string $table = 'mindmap_usages';

    protected array $fillable = [
        'user_id',
        'quota_date',
        'count',
    ];

    protected array $casts = [
        'count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
