<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Creem extends Model
{
    use HasFactory;

    /** 一般 product：沒有試用期，每個人第一期就計費。 */
    public const string VARIANT_STANDARD = 'standard';

    /** 含 trial_period_days 的 product，只給還有首月免費資格的人。 */
    public const string VARIANT_TRIAL = 'trial';

    protected ?string $table = 'creems';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'foreign_type',
        'foreign_id',
        'creem_id',
        'creem_detail',
        'variant',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'creem_detail' => 'array',
        'variant'      => 'string',
    ];

    public function price(): BelongsTo
    {
        return $this->belongsTo($this->foreign_type, 'foreign_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo($this->foreign_type, 'foreign_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo($this->foreign_type, 'foreign_id', 'id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo($this->foreign_type, 'foreign_id', 'id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo($this->foreign_type, 'foreign_id', 'id');
    }
}
