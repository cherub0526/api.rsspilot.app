<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Subscription extends Model
{
    use HasUlids;

    use SoftDeletes;

    use HasFactory;

    public const string STATUS_PAYING = 'paying';

    public const string STATUS_TRIAL = 'trial';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_CANCELED = 'canceled';

    public const string PAYMENT_METHOD_PADDLE = 'paddle';

    public const string PAYMENT_METHOD_STRIPE = 'stripe';

    public const string PAYMENT_METHOD_TRIAL = 'trial';

    public static array $statusMaps = [
        self::STATUS_PAYING   => '付款中',
        self::STATUS_TRIAL    => '試用中',
        self::STATUS_ACTIVE   => '訂閱中',
        self::STATUS_CANCELED => '已取消',
    ];

    public static array $paymentMethodMaps = [
        self::PAYMENT_METHOD_PADDLE => 'Paddle',
        self::PAYMENT_METHOD_STRIPE => 'Stripe',
    ];

    protected ?string $table = 'subscriptions';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'user_id',
        'plan_id',
        'price_id',
        'payment_method',
        'start_date',
        'next_date',
        'cancellation_date',
        'last_charged_date',
        'status',
        'note',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'user_id'           => 'string',
        'plan_id'           => 'string',
        'price_id'          => 'string',
        'payment_method'    => 'string',
        'start_date'        => 'datetime',
        'next_date'         => 'datetime',
        'cancellation_date' => 'datetime',
        'last_charged_date' => 'datetime',
        'status'            => 'string',
        'note'              => 'string',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id', 'id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'subscription_id', 'id');
    }

    public function paddle(): Builder|HasOne
    {
        return $this->hasOne(Paddle::class, 'foreign_id', 'id')
            ->where('foreign_type', self::class);
    }

    public function stripe(): Builder|HasOne
    {
        return $this->hasOne(Stripe::class, 'foreign_id', 'id')
            ->where('foreign_type', self::class);
    }

    public function scopeActive($query)
    {
        $now = now();

        return $query->where(function ($q) use ($now) {
            $q->where('status', self::STATUS_ACTIVE)
                ->orWhere(function ($sub) use ($now) {
                    $sub->where('status', self::STATUS_TRIAL)
                        ->where(function ($d) use ($now) {
                            $d->whereNull('next_date')
                                ->orWhere('next_date', '>=', $now);
                        });
                });
        });
    }
}
