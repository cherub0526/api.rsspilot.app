<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;

class Setting extends Model
{
    protected ?string $table = 'settings';
    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'user_id',
        'data',
        'version',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'data'    => 'array',
        'version' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
