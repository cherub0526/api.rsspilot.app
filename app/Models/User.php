<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use App\Utils\Const\ISO6391;
use Hyperf\Database\Model\Builder;
use Hypervel\Sanctum\HasApiTokens;
use App\Relations\UlidBelongsToMany;
use Hyperf\Database\Model\SoftDeletes;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Concerns\HasUlids;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Foundation\Auth\User as Authenticatable;

/**
 * @property string $id
 * @property null|string $account
 * @property string $name
 * @property null|string $email
 * @property null|Carbon $email_verified_at
 * @property string $password
 * @property null|string $social_type
 * @property null|string $provider_id
 * @property null|string $avatar
 * @property null|Carbon $tokens_invalid_before
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read null|Stripe $stripe
 */
class User extends Authenticatable
{
    use HasUlids;

    /**
     * 使用者自己產生的 API key（personal access token）。
     *
     * 只給 /mcp 那條路用；自家前端走的是 jwt guard，兩者刻意分開。
     */
    use HasApiTokens;

    use HasFactory;

    use SoftDeletes;

    public const string SOCIAL_TYPE_LOCAL = 'local';

    public const string SOCIAL_TYPE_FACEBOOK = 'facebook';

    public const string SOCIAL_TYPE_GOOGLE = 'google';

    /**
     * 使用者未設定 AI 回應語言時採用的語言代碼，與 config('app.locale') 的預設值一致。
     */
    public const string DEFAULT_AI_LANGUAGE = 'en';

    /**
     * 對應 users 資料表的 varchar(255)。社群登入帶進來的名稱與頭像網址寫入前依此截斷——
     * 測試環境是 sqlite，不會因為超長而失敗，MySQL 會。
     */
    public const int NAME_MAX_LENGTH = 255;

    public const int AVATAR_MAX_LENGTH = 255;

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

    protected array $casts = [
        'tokens_invalid_before' => 'datetime',
    ];

    public function oauths(): HasMany
    {
        return $this->hasMany(Oauth::class, 'user_id', 'id');
    }

    public function customPrompts(): HasMany
    {
        return $this->hasMany(CustomPrompt::class, 'user_id', 'id');
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
        ))->withTimestamps()->withPivot('notify', 'synced_at');
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
     * settings 資料列建帳號時就會建立（UserObserver::created），但 data 內不保證
     * 有 ai.language，直接讀取會觸發 warning，而 Hyperf 的 ErrorExceptionHandler
     * 會把 warning 轉成 ErrorException，讓整個請求變成 500。
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

    /**
     * AI 回應語言的「代碼」（如 ja、zh-TW），供快取鍵與 API 回應使用。
     *
     * 與 aiLanguageName() 讀同一個設定值，差別只在給誰看：prompt 要的是語言名稱，
     * 資料庫欄位與前端比對要的是代碼。兩者必須同源，否則會出現「用日文產生、
     * 存成英文」這種對不起來的資料。
     */
    public function aiLanguageCode(): string
    {
        $data = $this->setting()->first()?->data ?? [];
        $code = $data['ai']['language'] ?? self::DEFAULT_AI_LANGUAGE;

        return is_string($code) && $code !== '' ? $code : self::DEFAULT_AI_LANGUAGE;
    }

    /**
     * 使用者保存的介面語系，沒有設定或設定值已不在白名單內時回傳 null。
     *
     * 回 null 而不是回預設值，是為了讓呼叫端能區分「沒有偏好」與「偏好剛好
     * 等於預設值」——SetLocale 要靠這個差別決定該不該退回 Accept-Language。
     *
     * 白名單會隨 lang/ 底下的資料夾增減，所以舊資料可能存著已經移除的語系；
     * 直接餵給 App::setLocale() 會讓整站靜默落回 fallback，這裡先擋掉。
     */
    public function uiLocale(): ?string
    {
        $locale = ($this->setting()->first()?->data ?? [])['locale'] ?? null;

        return in_array($locale, config('app.available_locales'), true) ? $locale : null;
    }

    /**
     * 新帳號建立時，把註冊當下的語系寫進設定：介面語系與 AI 回覆語言都用它。
     *
     * 設定列由 UserObserver::created 建好（空的），這裡只合併進去。少了這一步，
     * 新帳號的 uiLocale() 是 null、AI 語言是 DEFAULT_AI_LANGUAGE，前端設定頁、
     * 後端寄信與 AI 回覆都會落回 en——即使使用者是在繁中介面註冊的。
     *
     * 兩個欄位填同一個值，是因為前端設定頁把它們合併成單一語言選項；
     * 介面語系白名單的每個代碼也都在 ISO6391::LANGUAGES 裡，AI 端收得下。
     * 傳 null（請求沒帶可用的語系）時不動，保留「沒有偏好」的狀態。
     */
    public function seedLocale(?string $locale): void
    {
        if ($locale === null) {
            return;
        }

        $setting = $this->setting()->firstOrCreate(['user_id' => $this->getKey()], ['data' => []]);

        $setting->update(['data' => array_merge($setting->data ?? [], [
            'locale' => $locale,
            'ai'     => ['language' => $locale],
        ])]);
    }

    public function avatars(): HasMany
    {
        return $this->hasMany(UserAvatar::class, 'user_id', 'id');
    }

    /**
     * 簽發時間（JWT 的 iat）早於 `tokens_invalid_before` 的登入憑證一律不收（token-invalidation）。
     *
     * 照秒比：iat 只到秒，欄位存進資料庫也只到秒。同一秒簽發的放行，重設密碼回應裡那張新 token
     * 才不會被自己擋掉；同一秒內、在重設之前簽發的舊 token 也會被放行，security 接受這個代價。
     */
    public function acceptsTokenIssuedAt(int $issuedAt): bool
    {
        return $this->tokens_invalid_before === null || $issuedAt >= $this->tokens_invalid_before->getTimestamp();
    }

    /**
     * 作廢這個使用者目前所有的登入憑證：重設密碼、刪除帳號時呼叫。
     *
     * - JWT：寫入 `tokens_invalid_before`，之前簽發的由 jwt guard 拒絕，換發也就跟著斷掉。
     * - API key（sanctum）：不會過期，比 JWT 更長效，直接刪掉，使用者要重新產生。
     *
     * 用 query 寫入、不走 save()：刪除帳號時是在 deleted 事件裡呼叫，save() 會再觸發一輪 observer
     * （包括同步到 Stripe 的那一個）。不套 soft delete 的 scope，已刪除的列也寫得到。
     */
    public function invalidateTokens(): void
    {
        $now = now()->startOfSecond();

        $this->newQueryWithoutScopes()->whereKey($this->getKey())->update(['tokens_invalid_before' => $now]);
        $this->tokens_invalid_before = $now;
        $this->syncOriginalAttribute('tokens_invalid_before');

        // 逐筆刪，不用 tokens()->delete()：sanctum 只在 deleting 事件裡清 token 快取，批次刪除不會觸發，
        // 打開 SANCTUM_CACHE_ENABLED 時被刪的 key 會繼續有效到快取過期（security r1 Low-1）。
        // 一個使用者的 key 很少，多幾次查詢可以忽略。
        foreach ($this->tokens()->get() as $token) {
            $token->delete();
        }
    }
}
