<?php

declare(strict_types=1);

namespace App\Models;

use App\Relations\UlidBelongsToMany;
use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\HasMany;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

/**
 * 使用者可選的 AI 模型。
 *
 * 與 App\Utils\AI\OpenRouterModels 是兩件事：那一支決定「系統某個用途預設用哪個
 * 模型」，這個 model 是「使用者能挑的清單」。
 *
 * 「誰能用哪些模型」一律看 plans() 這個關聯，model 身上沒有層級欄位——
 * 標籤與授權分開存放的結果就是兩者會漂移。
 */
class AiModel extends Model
{
    use HasUlids;

    use HasFactory;

    use SoftDeletes;

    protected ?string $table = 'ai_models';

    protected array $fillable = [
        'name',
        'provider_model',
        'supports_thinking',
        'input_price',
        'output_price',
        'enabled',
        'sort',
        'synced_at',
    ];

    protected array $casts = [
        'supports_thinking' => 'boolean',
        // decimal cast 保留字串形式，避免浮點誤差把 0.4 變成 0.40000000000000002。
        'input_price'  => 'decimal:6',
        'output_price' => 'decimal:6',
        'enabled'      => 'boolean',
        'sort'         => 'integer',
        'synced_at'    => 'datetime',
    ];

    public function customPrompts(): HasMany
    {
        return $this->hasMany(CustomPrompt::class, 'model_id', 'id');
    }

    /**
     * 哪些方案能使用這個模型。Plan::aiModels() 的反向。
     */
    public function plans(): UlidBelongsToMany
    {
        $instance = $this->newRelatedInstance(Plan::class);

        return (new UlidBelongsToMany(
            $instance->newQuery(),
            $this,
            'plan_ai_models',
            'ai_model_id',
            'plan_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'plans'
        ))->withTimestamps();
    }
}
