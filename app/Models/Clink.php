<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

/**
 * Clink 上的物件與我方資料的對應，形狀照 `Creem`。
 *
 * - `Plan`：1 列，Clink product id
 * - `Price`：2 列（standard / trial），Clink price id
 * - `Subscription`：1 列，結帳時存 session id（sess_），同步後改寫成 subscription id（sub_）
 *
 * @property string $id
 * @property string $foreign_id
 * @property string $foreign_type
 * @property null|string $clink_id
 * @property null|array $clink_detail
 * @property string $variant
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
class Clink extends Model
{
    use HasUlids;

    /** 不含試用的 price，每個人第一期就計費。 */
    public const string VARIANT_STANDARD = 'standard';

    /** 含 trialPeriodDays 的 price，只給還有新客優惠資格的人。 */
    public const string VARIANT_TRIAL = 'trial';

    /** @var list<string> */
    public const array VARIANTS = [self::VARIANT_STANDARD, self::VARIANT_TRIAL];

    protected ?string $table = 'clinks';

    protected array $fillable = [
        'foreign_type',
        'foreign_id',
        'clink_id',
        'clink_detail',
        'variant',
    ];

    protected array $casts = [
        'clink_detail' => 'array',
        'variant'      => 'string',
    ];

    public function foreign(): BelongsTo
    {
        return $this->belongsTo($this->foreign_type, 'foreign_id', 'id');
    }
}
