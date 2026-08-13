<?php

declare(strict_types=1);

namespace App\Models;

use App\Utils\Const\ISO6391;
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

    /**
     * 使用者未設定 AI 回應語言時採用的語言代碼，與 config('app.locale') 的預設值一致。
     */
    public const string DEFAULT_AI_LANGUAGE = 'en';

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

    /**
     * AI 回應語言的名稱，供 prompt 直接引用。
     *
     * settings 資料列要等使用者第一次更新設定才會建立（見 SettingsController::update），
     * data 內也不保證有 ai.language，所以這裡兩層都不能假設存在——
     * 直接讀取會觸發 warning，而 Hyperf 的 ErrorExceptionHandler 會把 warning 轉成
     * ErrorException，讓整個請求變成 500。
     *
     * `ISO6391::getNameByCode()` 是 array_search，未登錄的代碼會回傳 false，
     * 此時以代碼本身頂替，避免 prompt 裡的語言指示變成空字串。
     */
    public function aiLanguageName(): string
    {
        $data = $this->setting()->first()?->data ?? [];
        $code = $data['ai']['language'] ?? self::DEFAULT_AI_LANGUAGE;
        $name = ISO6391::getNameByCode($code);

        return is_string($name) ? $name : $code;
    }

    public function avatars(): HasMany
    {
        return $this->hasMany(UserAvatar::class, 'user_id', 'id');
    }
}
