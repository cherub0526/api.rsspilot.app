<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\SoftDeletes;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Image extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    protected ?string $table = 'images';
    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'foreign_id',
        'foreign_type',
        'filename',
        'path',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class, 'foreign_id', 'id');
    }
}
