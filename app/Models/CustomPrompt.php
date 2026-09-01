<?php

declare(strict_types=1);

namespace App\Models;

use App\Relations\UlidBelongsToMany;
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
        'model_id',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 這份設定要用的 AI 模型。可為 null——沒指定就退回系統預設。
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id', 'id');
    }

    /**
     * 這份設定套用到哪些頻道／清單。
     *
     * 用 UlidBelongsToMany 而不是原生的 belongsToMany：中介表的主鍵是 ULID，
     * 而 Eloquent 的 attach() 只寫外鍵與時間戳，不會產生 id，直接違反 NOT NULL。
     * User::sources() 也是走同一條路。
     *
     * 中介表沒有 user_id，所以它擋不住「掛上別人訂閱的 source」——那件事由
     * Controller 在寫入前驗證，見 CustomPromptsController::resolveSourceIds()。
     */
    public function sources(): UlidBelongsToMany
    {
        $instance = $this->newRelatedInstance(Source::class);

        return (new UlidBelongsToMany(
            $instance->newQuery(),
            $this,
            'custom_prompt_sources',
            'custom_prompt_id',
            'source_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'sources'
        ))->withTimestamps();
    }
}
