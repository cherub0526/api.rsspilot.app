<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Stripe extends Model
{
    use HasFactory;

    protected ?string $table = 'stripes';

    protected array $fillable = [
        'foreign_type',
        'foreign_id',
        'stripe_id',
        'stripe_detail',
    ];

    protected array $casts = [
        'stripe_detail' => 'array',
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
