<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;

class CustomPrompt extends Model
{
    protected ?string $table = 'custom_prompts';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'user_id',
        'title',
        'content',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
