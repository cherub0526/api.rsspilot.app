<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Builder;
use App\Relations\UlidBelongsToMany;
use Hyperf\Database\Model\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasUlids;

    use HasFactory;

    use SoftDeletes;

    public const string SOCIAL_TYPE_LOCAL = 'local';

    public const string SOCIAL_TYPE_FACEBOOK = 'facebook';

    public const string SOCIAL_TYPE_GOOGLE = 'google';

    protected array $with = ['paddle'];

    protected ?string $table = 'users';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'account',
        'name',
        'email',
        'email_verified_at',
        'password',
        'social_type',
        'provider_id',
        'avatar',
    ];

    public function oauths(): HasMany
    {
        return $this->hasMany(Oauth::class, 'user_id', 'id');
    }

    public function rss()
    {
        return $this->belongsToMany(
            Rss::class,
            'userables',
            'user_id',
            'rss_id'
        )->wherePivot('media_id', null)->withTimestamps();
    }

    public function sources(): UlidBelongsToMany
    {
        $instance = $this->newRelatedInstance(Source::class);

        return (new UlidBelongsToMany(
            $instance->newQuery(),
            $this,
            'user_sources',
            'user_id',
            'source_id',
            $this->getKeyName(),
            $instance->getKeyName(),
            'sources'
        ))->withTimestamps()->withPivot('notify');
    }

    public function watchHistory(): HasMany
    {
        return $this->hasMany(WatchHistory::class, 'user_id', 'id');
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class, 'user_id', 'id');
    }

    public function summaryConfigs(): HasMany
    {
        return $this->hasMany(SummaryConfig::class, 'user_id', 'id');
    }

    public function media()
    {
        return $this->belongsToMany(
            Media::class,
            'userables',
            'user_id',
            'media_id'
        )->withTimestamps();
    }

    public function paddle(): Builder|HasOne
    {
        return $this->hasOne(Paddle::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }

    public function stripe(): Builder|HasOne
    {
        return $this->hasOne(Stripe::class, 'foreign_id', 'id')->where('foreign_type', self::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'user_id', 'id');
    }

    public function setting(): HasOne
    {
        return $this->hasOne(Setting::class, 'user_id', 'id');
    }

    public function avatars(): HasMany
    {
        return $this->hasMany(UserAvatar::class, 'user_id', 'id');
    }
}
