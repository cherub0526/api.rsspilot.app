<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Builder;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Price extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    public const string UNIT_MONTHLY = 'monthly';

    public const string UNIT_QUARTERLY = 'quarterly';

    public const string UNIT_ANNUALLY = 'annually';

    public static array $unitMaps = [
        self::UNIT_MONTHLY   => '每月',
        self::UNIT_QUARTERLY => '每季',
        self::UNIT_ANNUALLY  => '每年',
    ];

    protected array $with = ['paddle'];

    protected ?string $table = 'prices';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'plan_id',
        'unit',
        'price',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function paddle(): Builder|HasOne
    {
        return $this->hasOne(Paddle::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }

    public function stripe(): Builder|HasOne
    {
        return $this->hasOne(Stripe::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }
}
