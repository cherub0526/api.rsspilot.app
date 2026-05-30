<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Builder;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasUlids;

    use SoftDeletes;

    protected ?string $table = 'transactions';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'subscription_id',
        'billing_date',
        'amount',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'subscription_id' => 'integer',
        'billing_date'    => 'datetime',
        'amount'          => 'float',
        'status'          => 'string',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'id');
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
