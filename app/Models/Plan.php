<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Hyperf\Database\Model\Builder;
use App\Relations\UlidBelongsToMany;
use Hyperf\Database\Model\SoftDeletes;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $id
 * @property string $title
 * @property null|string $description
 * @property int $channel_limit
 * @property int $video_limit
 * @property int $chat_limit
 * @property int $mindmap_limit
 * @property int $sort
 * @property string $status
 * @property bool $download_enabled
 * @property bool $agent_enabled
 * @property bool $advanced_model_enabled
 * @property bool $custom_summary_enabled
 * @property bool $screenshot_enabled
 * @property bool $thinking_enabled
 * @property bool $mcp_enabled
 * @property bool $skill_enabled
 * @property string $ai_quality
 * @property null|array $ai_routing
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|Carbon $deleted_at
 * @property-read Collection<int, Price> $prices
 * @property-read null|Stripe $stripe
 * @property-read null|Clink $clink
 */
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
    public const bool SCREENSHOT_ENABLED_DEFAULT = false;

    public static array $statusMaps = [
        self::STATUS_ACTIVE   => 'Active',
        self::STATUS_INACTIVE => 'Inactive',
    ];

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
        'mindmap_limit',
        'download_enabled',
        'agent_enabled',
        'thinking_enabled',
        'mcp_enabled',
        'skill_enabled',
        'advanced_model_enabled',
        'custom_summary_enabled',
        'screenshot_enabled',
        'ai_quality',
        'ai_routing',
        'sort',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'sort'                   => 'integer',
        'ai_routing'             => 'array',
        'download_enabled'       => 'boolean',
        'agent_enabled'          => 'boolean',
        'thinking_enabled'       => 'boolean',
        'mcp_enabled'            => 'boolean',
        'skill_enabled'          => 'boolean',
        'advanced_model_enabled' => 'boolean',
        'custom_summary_enabled' => 'boolean',
        'screenshot_enabled'     => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'plan_id', 'id');
    }

    public function stripe(): Builder|HasOne
    {
        return $this->hasOne(Stripe::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }

    /** 這個方案在 Clink 上的 product。Clink 有 product → 多 price 的階層，價格掛在它底下。 */
    public function clink(): Builder|HasOne
    {
        return $this->hasOne(Clink::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }

    /**
     * 這個方案送給 OpenRouter 的路由設定，null 表示不覆寫。
     *
     * **與 `ai_quality` 是兩件事。** `ai_quality`（pro / advanced / deep）是定價頁
     * 的行銷文案，桌面端拿它 switch 成「Pro / Advanced / Deep」三個標籤；這一欄是
     * 實際的路由。兩者刻意不綁在一起——調成本不該被迫改文案，改文案也不該動成本。
     *
     * @return null|array<string, mixed>
     */
    public function aiRouting(): ?array
    {
        $routing = $this->getAttribute('ai_routing');

        return is_array($routing) && $routing !== [] ? $routing : null;
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
