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
     * 所以「試用只送第一次」得靠同一個 price 建兩個 product 來守。
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

    /** 這是年繳嗎。只有年繳認首期折價，見 firstPeriodPrice()。 */
    public function isAnnual(): bool
    {
        return $this->unit === self::UNIT_ANNUALLY;
    }

    /**
     * 這個 price 送幾天免費試用；不送的話為 null。
     *
     * 免費方案不送——$0 掛試用期沒有意義，而且會讓訂閱狀態多一種 trialing 要處理
     * （Paddle 那條路也是這樣判，見 `Paddle\Sync::trialPeriodFor()`）。
     *
     * 設了首期折價的 price 也不送：那時改走折扣碼那條路，兩種優惠互斥
     * （見 `CreemSubscriptionService::grantsTrial()`）。這裡跟著回 null，定價頁
     * 才不會同時講「免費試用 14 天」與「首年折價」。
     */
    public function trialDays(): ?int
    {
        if ((float) $this->price <= 0 || $this->introDiscountCents() !== null) {
            return null;
        }

        return Subscription::TRIAL_DAYS;
    }

    /**
     * 首期優惠價（年繳首年），沒有優惠時為 null。
     *
     * **只有年繳認這個欄位。** 設了它就等於把這個 price 從「免費試用」那條路切到
     * 「首期折價」那條路——兩者刻意不疊加，不然同一個人一次拿到兩份優惠。
     * 目前每一筆 price 都是 null（owner 決定不降價），所以實際走的都是試用那條。
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
