<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;

class UserSource extends Model
{
    use HasUlids;

    protected ?string $table = 'user_sources';

    protected array $fillable = [
        'user_id',
        'source_id',
        'notify',
    ];

    protected array $casts = [
        'notify' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'id');
    }
}
