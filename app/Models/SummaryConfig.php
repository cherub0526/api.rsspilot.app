<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\SoftDeletes;
use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hyperf\Database\Model\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class SummaryConfig extends Model
{
    use HasUlids;
    use SoftDeletes;
    use HasFactory;

    public const string PROMPT_TYPE_DEFAULT = 'default';
    public const string PROMPT_TYPE_NOTES = 'notes';
    public const string PROMPT_TYPE_BUSINESS = 'business';
    public const string PROMPT_TYPE_TLDR = 'tldr';
    public const string PROMPT_TYPE_CUSTOM = 'custom';

    public static array $promptTypeMaps = [
        self::PROMPT_TYPE_DEFAULT  => '預設',
        self::PROMPT_TYPE_NOTES    => '筆記',
        self::PROMPT_TYPE_BUSINESS => '商業',
        self::PROMPT_TYPE_TLDR     => 'TL;DR',
        self::PROMPT_TYPE_CUSTOM   => '自訂',
    ];

    protected ?string $table = 'summary_configs';

    protected array $fillable = [
        'user_id',
        'title',
        'prompt_type',
        'content',
        'ai_model',
    ];

    protected array $casts = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(
            Source::class,
            'summary_config_sources',
            'config_id',
            'source_id'
        );
    }
}
