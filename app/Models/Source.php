<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\HasMany;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Source extends Model
{
    use HasUlids;
    use SoftDeletes;
    use HasFactory;

    public const string TYPE_YOUTUBE_CHANNEL = 'youtube_channel';
    public const string TYPE_YOUTUBE_PLAYLIST = 'youtube_playlist';

    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    public static array $typeMaps = [
        self::TYPE_YOUTUBE_CHANNEL  => 'YouTube 頻道',
        self::TYPE_YOUTUBE_PLAYLIST => 'YouTube 播放清單',
    ];

    protected ?string $table = 'sources';

    protected array $fillable = [
        'type',
        'external_id',
        'title',
        'url',
        'thumbnail',
        'description',
        'metadata',
        'last_synced_at',
        'status',
    ];

    protected array $casts = [
        'metadata'       => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function userSources(): HasMany
    {
        return $this->hasMany(UserSource::class, 'source_id', 'id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'source_id', 'id');
    }

    public function summaryConfigs()
    {
        return $this->belongsToMany(SummaryConfig::class, 'summary_config_sources', 'source_id', 'config_id');
    }
}
