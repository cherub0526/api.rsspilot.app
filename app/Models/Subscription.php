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

    public const string PAYMENT_METHOD_CREEM = 'creem';

    public const string PAYMENT_METHOD_TRIAL = 'trial';

    /**
     * 首月免費的長度（月）。
     *
     * 一個數字有三個落點，改的時候要一起動：Paddle 是設在 price 的
     * `trial_period`（`paddle:sync`）、Stripe 是結帳時的 `trial_end`、我們自己的
     * `next_date` 則照金流商回報的日期寫入。
     */
    public const int FREE_MONTHS = 1;

    public static array $statusMaps = [
        self::STATUS_PAYING   => '付款中',
        self::STATUS_TRIAL    => '試用中',
        self::STATUS_ACTIVE   => '訂閱中',
        self::STATUS_CANCELED => '已取消',
    ];

    public static array $paymentMethodMaps = [
        self::PAYMENT_METHOD_PADDLE => 'Paddle',
        self::PAYMENT_METHOD_STRIPE => 'Stripe',
        self::PAYMENT_METHOD_CREEM  => 'Creem',
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

    /**
     * Paddle 是否開放新結帳。只管「新的」：webhook 與取消訂閱照常處理既有的
     * Paddle 訂閱，見 config/services.php 的 `paddle.enabled`。
     */
    public static function paddleEnabled(): bool
    {
        return (bool) config('services.paddle.enabled');
    }

    /**
     * 目前開放新結帳的金流。
     *
     * @return array<int, string>
     */
    public static function checkoutPaymentMethods(): array
    {
        return array_values(array_filter([
            self::paddleEnabled() ? self::PAYMENT_METHOD_PADDLE : null,
            self::PAYMENT_METHOD_STRIPE,
            self::PAYMENT_METHOD_CREEM,
        ]));
    }

    /**
     * 結帳沒有指定 `paymentMethod` 時要用哪一家。
     *
     * 認不得的值不拋例外：這個變數打錯字的後果應該是「用回舊的」，不是「所有人
     * 都結不了帳」。Paddle 開著時退回 Paddle（原本的行為）；關著時退回 Creem，
     * 否則 PAYMENT_DEFAULT_PROVIDER 還寫著 paddle 的環境會整站結不了帳。
     */
    public static function defaultPaymentMethod(): string
    {
        $configured = (string) env('PAYMENT_DEFAULT_PROVIDER', self::PAYMENT_METHOD_PADDLE);

        if (in_array($configured, self::checkoutPaymentMethods(), true)) {
            return $configured;
        }

        return self::paddleEnabled() ? self::PAYMENT_METHOD_PADDLE : self::PAYMENT_METHOD_CREEM;
    }

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

    public function creem(): Builder|HasOne
    {
        return $this->hasOne(Creem::class, 'foreign_id', 'id')
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
