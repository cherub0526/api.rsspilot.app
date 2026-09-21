<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

/**
 * 一支影片的心智圖：由既有摘要產生的多層 markdown 大綱，前端以 markmap 繪成圖。
 *
 * 語言是使用者的 AI 語言（setting.ai.language），與介面語系無關，也與摘要自身的
 * locale 無關——輸入可能是英文摘要，輸出仍然照使用者設定的語言產生。
 */
class Mindmap extends Model
{
    use HasUlids;

    use HasFactory;

    public const STATUS_CREATED = 'created';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public static array $statusMaps = [
        self::STATUS_CREATED    => '已建立',
        self::STATUS_PROCESSING => '處理中',
        self::STATUS_COMPLETED  => '完成',
        self::STATUS_FAILED     => '失敗',
    ];

    protected ?string $table = 'mindmaps';

    protected array $fillable = [
        'media_id',
        'summary_id',
        'language',
        'user_id',
        'markdown',
        'status',
        'ai_model',
    ];

    protected array $casts = [
        'media_id'   => 'string',
        'summary_id' => 'string',
        'language'   => 'string',
        'user_id'    => 'string',
        'markdown'   => 'string',
        'status'     => 'string',
        'ai_model'   => 'string',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }

    /**
     * 產生這張心智圖所依據的摘要。摘要換了（重跑、改用自訂設定）就是另一列心智圖。
     */
    public function summary(): BelongsTo
    {
        return $this->belongsTo(Summary::class, 'summary_id', 'id');
    }

    /**
     * 這張心智圖專屬的使用者；目前一律為 null（全站共用），欄位保留給未來的
     * per-user 心智圖，形狀比照 Summary。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
