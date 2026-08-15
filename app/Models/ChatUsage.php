<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * 一位使用者在一個額度日內的提問次數。
 *
 * 刻意不記 plan_id：額度是拿「當下方案的上限」比對「當日總用量」，
 * 依方案分桶會讓升級再降級變成重置額度的手段。
 *
 * @property string $user_id
 * @property string $quota_date
 * @property int $count
 */
class ChatUsage extends Model
{
    protected ?string $table = 'chat_usages';

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
