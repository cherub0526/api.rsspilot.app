<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;

class Summary extends Model
{
    use HasUlids;

    public const LOCALE_ZH_TW = 'zh_tw';

    public const LOCALE_EN = 'en';

    public const STATUS_CREATED = 'created';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public static array $localeMaps = [
        self::LOCALE_ZH_TW => '繁體中文',
        self::LOCALE_EN    => '英文',
    ];

    public static array $statusMaps = [
        self::STATUS_CREATED    => '已建立',
        self::STATUS_PROCESSING => '處理中',
        self::STATUS_COMPLETED  => '完成',
        self::STATUS_FAILED     => '失敗',
    ];

    protected ?string $table = 'summaries';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'media_id',
        'config_id',
        'locale',
        'text',
        'status',
        'ai_model',
        'prompt_type',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'media_id'    => 'string',
        'locale'      => 'string',
        'text'        => 'array',
        'ai_model'    => 'string',
        'prompt_type' => 'string',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(SummaryConfig::class, 'config_id', 'id');
    }
}
