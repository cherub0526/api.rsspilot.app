<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $id
 * @property string $foreign_id
 * @property string $foreign_type
 * @property string $filename
 * @property null|string $path
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|Carbon $deleted_at
 */
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
