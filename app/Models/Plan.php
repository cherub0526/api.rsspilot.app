<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Builder;
use App\Relations\UlidBelongsToMany;
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

    public const string AI_QUALITY_PRO = 'pro';
    public const string AI_QUALITY_ADVANCED = 'advanced';
    public const string AI_QUALITY_DEEP = 'deep';

    public const bool DOWNLOAD_ENABLED_DEFAULT = false;
    public const bool AGENT_ENABLED_DEFAULT = false;
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
        'ai_quality',
        'sort',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'sort'                   => 'integer',
        'download_enabled'       => 'boolean',
        'agent_enabled'          => 'boolean',
        'advanced_model_enabled' => 'boolean',
        'custom_summary_enabled' => 'boolean',
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

    public function stripe(): Builder|HasOne
    {
        return $this->hasOne(Stripe::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }

    /**
     * 這個方案能使用的 AI 模型。
     *
     * 這是「誰能用哪些模型」的唯一授權來源——ai_models 沒有層級欄位，前端的
     * 分組也是從這個關聯推導的（見 create_plan_ai_models_table 的說明）。
     *
     * 用 UlidBelongsToMany：中介表主鍵是 ULID，原生的 attach() 不會產生 id。
     */
    public function aiModels(): UlidBelongsToMany
    {
        $instance = $this->newRelatedInstance(AiModel::class);

        return (new UlidBelongsToMany(
            $instance->newQuery(),
            $this,
            'plan_ai_models',
            'plan_id',
            'ai_model_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'aiModels'
        ))->withTimestamps();
    }
}
