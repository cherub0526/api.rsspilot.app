<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\HasMany;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Rss extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    public const TYPE_YOUTUBE = 'youtube';

    public static array $typeMaps = [
        self::TYPE_YOUTUBE => 'Youtube',
    ];

    protected ?string $table = 'rss';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'type',
        'list_id',
        'title',
        'url',
        'comment',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'url' => 'string',
    ];

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'userables',
            'rss_id',
            'user_id'
        )->wherePivot('media_id', null)->withTimestamps();
    }

    public function syncHistories(): HasMany
    {
        return $this->hasMany(RssSyncHistory::class, 'rss_id', 'id');
    }
}
