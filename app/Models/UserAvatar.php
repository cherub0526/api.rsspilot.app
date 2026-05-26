<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

class UserAvatar extends Model
{
    use HasUlids;

    protected ?string $table = 'user_avatars';

    protected array $fillable = [
        'user_id',
        'filename',
        'path',
    ];

    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
