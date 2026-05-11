<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class WatchHistory extends Model
{
    use HasUlids;
    use HasFactory;

    protected ?string $table = 'watch_history';

    protected array $fillable = [
        'user_id',
        'media_id',
        'progress_seconds',
        'completed',
        'watched_at',
    ];

    protected array $casts = [
        'progress_seconds' => 'integer',
        'completed'        => 'boolean',
        'watched_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }
}
