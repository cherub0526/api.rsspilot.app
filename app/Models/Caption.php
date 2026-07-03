<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Caption extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    public const LOCAL_ZH_TW = 'zh_tw';

    public const LOCAL_EN = 'en';

    public static array $localeMaps = [
        self::LOCAL_ZH_TW => '繁體中文',
        self::LOCAL_EN    => '英文',
    ];

    public static array $groqMaps = [
        'English' => self::LOCAL_EN,
    ];

    protected ?string $table = 'captions';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'media_id',
        'locale',
        'primary',
        'text',
        'segments',
        'word_segments',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'media_id'      => 'string',
        'locale'        => 'string',
        'text'          => 'string',
        'segments'      => 'array',
        'word_segments' => 'array',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }
}
