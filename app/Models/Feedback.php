<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\SoftDeletes;
use Hyperf\Database\Model\Relations\HasMany;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Feedback extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    public const string STATUS_CREATED = 'created';
    public const string STATUS_SOLVED = 'solved';

    protected ?string $table = 'feedbacks';
    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'user_id',
        'content',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'foreign_id', 'id')
            ->where('foreign_type', self::class);
    }
}
