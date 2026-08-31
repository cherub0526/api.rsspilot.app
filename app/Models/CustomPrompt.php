<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class CustomPrompt extends Model
{
    use HasUlids;
    use HasFactory;

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
