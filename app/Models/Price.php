<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Builder;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\HasMany;
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
        'first_period_price',
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

    /**
     * 這個 price 在 Creem 上的 product 們（含試用／不含試用兩個變體）。
     *
     * 是 hasMany 而不是 hasOne——Creem 的試用期綁在 product 上且結帳時不可覆寫，
     * 所以「首月免費只送第一次」得靠同一個 price 建兩個 product 來守。
     * 取用時一定要帶 variant 過濾，見 CreemSubscriptionService::productIdFor()。
     */
    public function creem(): Builder|HasMany
    {
        return $this->hasMany(Creem::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }

    /**
     * Stripe price 的金額（以美分為單位）。
     *
     * prices.price 一律是未稅 USD（見 docs/lore/subscription/pitfalls.md）。
     */
    public function stripeUnitAmount(): int
    {
        return (int) round((float) $this->price * 100);
    }

    /** 這是年繳嗎。年繳與月繳的新客優惠不同，見 introDiscountCents()。 */
    public function isAnnual(): bool
    {
        return $this->unit === self::UNIT_ANNUALLY;
    }

    /**
     * 首期優惠價（年繳首年），沒有優惠時為 null。
     *
     * **只有年繳認這個欄位。** 月繳的新客優惠是首月免費（`Creem::VARIANT_TRIAL`），
     * 兩者不疊加——首月免費 + 首年折價會讓同一個人一次拿到兩份優惠。
     */
    public function firstPeriodPrice(): ?float
    {
        if (!$this->isAnnual() || $this->first_period_price === null) {
            return null;
        }

        $first = (float) $this->first_period_price;

        // 優惠價 >= 原價等於沒有優惠；填成負數或 0 更不該靜靜生效。
        return $first > 0 && $first < (float) $this->price ? $first : null;
    }

    /**
     * 首期要折掉多少（美分）。沒有首期優惠時為 null。
     *
     * 回傳折扣金額而不是優惠價，是因為金流商那邊的 discount 物件吃的是折扣額：
     * 把「折多少」當成單一事實來源，續約價就永遠是 `price`，不會因為兩邊各算
     * 一次而對不起來。
     */
    public function introDiscountCents(): ?int
    {
        $first = $this->firstPeriodPrice();

        if ($first === null) {
            return null;
        }

        return (int) round(((float) $this->price - $first) * 100);
    }

    /**
     * Stripe price 的 recurring 參數，對應 unit 欄位。
     */
    public function stripeRecurring(): array
    {
        return match ($this->unit) {
            self::UNIT_QUARTERLY => ['interval' => 'month', 'interval_count' => 3],
            self::UNIT_ANNUALLY  => ['interval' => 'year', 'interval_count' => 1],
            default              => ['interval' => 'month', 'interval_count' => 1],
        };
    }
}
