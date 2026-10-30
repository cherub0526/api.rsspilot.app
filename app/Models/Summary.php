<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $id
 * @property string $media_id
 * @property null|string $user_id
 * @property string $locale
 * @property null|array $text
 * @property string $status
 * @property null|string $config_id
 * @property null|string $ai_model
 * @property null|string $prompt_type
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property-read null|Media $media
 */
class Summary extends Model
{
    use HasUlids;

    use HasFactory;

    public const LOCALE_ZH_TW = 'zh-TW';

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
        'user_id',
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
        'user_id'     => 'string',
        'locale'      => 'string',
        'text'        => 'array',
        'ai_model'    => 'string',
        'prompt_type' => 'string',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }

    /**
     * 這份摘要專屬的使用者；null 代表全站共用（見 Media::summaryFor()）。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(SummaryConfig::class, 'config_id', 'id');
    }

    /**
     * 當成 AI 素材時的文字：摘要正文 + 重點條列 + 關鍵字。心智圖與頻道 Skill 共用。
     *
     * key_points 是現成的條列骨架，直接對應 ## / ### 的階層；只給 content 那段散文
     * 而要求四層結構，等於請模型編造。short_summary 刻意不放——它與 content 內容
     * 重複，會讓模型產出重複的段落。
     *
     * 回傳空字串代表這份摘要不能拿來當素材（還沒跑完、或模型沒給正文）。
     */
    public function aiInput(): string
    {
        $text = $this->getAttribute('text');
        $text = is_array($text) ? $text : [];
        $long = is_array($text['long_summary'] ?? null) ? $text['long_summary'] : [];

        $parts = [];

        $content = trim((string) ($long['content'] ?? ''));
        if ($content !== '') {
            $parts[] = $content;
        }

        $keyPoints = array_filter(
            array_map('strval', is_array($long['key_points'] ?? null) ? $long['key_points'] : [])
        );
        if ($keyPoints !== []) {
            $parts[] = "Key points:\n- " . implode("\n- ", $keyPoints);
        }

        $keywords = array_filter(
            array_map('strval', is_array($long['keywords'] ?? null) ? $long['keywords'] : [])
        );
        if ($keywords !== []) {
            $parts[] = 'Keywords: ' . implode(', ', $keywords);
        }

        return trim(implode("\n\n", $parts));
    }
}
