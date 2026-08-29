<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Factories\HasFactory;

class Oauth extends Model
{
    use HasFactory;

    public const PROVIDER_FACEBOOK = 'facebook';

    public const PROVIDER_GOOGLE = 'google';

    public static array $providerMaps = [
        self::PROVIDER_FACEBOOK => 'Facebook',
        self::PROVIDER_GOOGLE   => 'Google',
    ];

    protected ?string $table = 'oauths';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'provider',
        'provider_id',
        'user_id',
        'token',
        'refresh_token',
        'expires_in',
        'data',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = [
        'expires_in' => 'integer',
        'data'       => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
