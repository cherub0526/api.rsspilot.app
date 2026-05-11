<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Plan extends Model
{
    use HasUlids;

    use HasFactory;

    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const bool DOWNLOAD_ENABLED_DEFAULT       = false;
    public const bool AGENT_ENABLED_DEFAULT          = false;
    public const bool ADVANCED_MODEL_ENABLED_DEFAULT = false;
    public const bool CUSTOM_SUMMARY_ENABLED_DEFAULT = false;

    public static array $statusMaps = [
        self::STATUS_ACTIVE   => 'Active',
        self::STATUS_INACTIVE => 'Inactive',
    ];

    protected array $with = ['paddle'];

    protected ?string $table = 'plans';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'title',
        'description',
        'channel_limit',
        'video_limit',
        'chat_limit',
        'download_enabled',
        'agent_enabled',
        'advanced_model_enabled',
        'custom_summary_enabled',
        'sort',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'sort'                     => 'integer',
        'download_enabled'         => 'boolean',
        'agent_enabled'            => 'boolean',
        'advanced_model_enabled'   => 'boolean',
        'custom_summary_enabled'   => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'plan_id', 'id');
    }

    public function paddle(): Builder|HasOne
    {
        return $this->hasOne(Paddle::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }
}
